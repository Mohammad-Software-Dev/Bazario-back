<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StripePayment;
use App\Services\StripeOrderPaymentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Illuminate\Support\Facades\DB;

class OrderCheckoutController extends Controller
{
    public function createPaymentIntent(Request $request, Order $order, StripeClient $stripe)
    {
        abort_unless($order->buyer_id === $request->user()->id, 403);
        abort_if($order->status !== 'draft', 422, __('orders.not_payable'));
        abort_if($order->total_amount <= 0, 422, __('orders.total_invalid'));

        return DB::transaction(function () use ($order, $stripe) {
            $order->update([
                'status' => 'requires_payment',
                'placed_at' => now(),
                'transfer_group' => $order->transfer_group ?: ('order_' . $order->id),
            ]);

            $idempotencyKey = 'pi_' . $order->id;

            $pi = $stripe->paymentIntents->create([
                'amount' => $order->total_amount,
                'currency' => strtolower($order->currency_iso),
                'automatic_payment_methods' => ['enabled' => true],
                'transfer_group' => $order->transfer_group,
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ], [
                'idempotency_key' => $idempotencyKey,
            ]);

            StripePayment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_intent_id' => $pi->id,
                    'status' => $pi->status,
                    'amount' => $order->total_amount,
                    'currency_iso' => $order->currency_iso,
                    'metadata' => $pi instanceof \Stripe\StripeObject ? $pi->toArray() : (array) $pi,
                ]
            );

            return response()->json([
                'client_secret' => $pi->client_secret,
                'payment_intent_id' => $pi->id,
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
        });
    }

    public function createCheckoutSession(Request $request, Order $order, StripeClient $stripe)
    {
        abort_unless($order->buyer_id === $request->user()->id, 403);
        abort_if(in_array($order->status, ['paid', 'refunded', 'partially_refunded'], true), 422, __('orders.not_payable'));
        abort_if($order->status !== 'draft', 422, __('orders.not_payable'));
        abort_if($order->total_amount <= 0, 422, __('orders.total_invalid'));

        $order->load(['items.serviceBooking', 'buyer']);
        abort_if($order->items->isEmpty(), 422, __('orders.total_invalid'));

        return DB::transaction(function () use ($order, $stripe) {
            $order->update([
                'status' => 'requires_payment',
                'placed_at' => now(),
                'transfer_group' => $order->transfer_group ?: ('order_' . $order->id),
            ]);

            $lineItems = $order->items
                ->map(fn ($item) => $this->buildStripeLineItem($item, $order->currency_iso))
                ->values()
                ->all();

            $successBaseUrl = config('stripe.checkout_success_url') ?: (config('app.url') . '/checkout/success');
            $cancelBaseUrl = config('stripe.checkout_cancel_url') ?: (config('app.url') . '/checkout/cancel');

            $successUrl = $successBaseUrl
                . (str_contains($successBaseUrl, '?') ? '&' : '?')
                . 'order_id=' . $order->id . '&session_id={CHECKOUT_SESSION_ID}';
            $cancelUrl = $cancelBaseUrl
                . (str_contains($cancelBaseUrl, '?') ? '&' : '?')
                . 'order_id=' . $order->id;

            $session = $stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => $lineItems,
                'customer_email' => $order->buyer?->email,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'payment_intent_data' => [
                    'transfer_group' => $order->transfer_group,
                    'metadata' => [
                        'order_id' => (string) $order->id,
                    ],
                ],
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ], [
                'idempotency_key' => 'cs_' . $order->id,
            ]);

            return response()->json([
                'checkout_url' => $session->url,
                'checkout_session_id' => $session->id,
                'order_id' => $order->id,
                'status' => $order->status,
            ]);
        });
    }

    public function reconcileCheckoutSession(
        Request $request,
        Order $order,
        StripeClient $stripe,
        StripeOrderPaymentService $payments
    ) {
        abort_unless($order->buyer_id === $request->user()->id, 403);

        $data = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        $session = $stripe->checkout->sessions->retrieve($data['session_id'], []);
        $sessionArray = $session instanceof \Stripe\StripeObject ? $session->toArray() : (array) $session;

        $sessionOrderId = $sessionArray['metadata']['order_id'] ?? null;
        abort_if((string) $sessionOrderId !== (string) $order->id, 422, 'Checkout session does not belong to this order.');

        $paymentStatus = (string) ($sessionArray['payment_status'] ?? '');
        if ($paymentStatus === 'paid') {
            $paymentObject = $payments->buildPaymentObjectFromCheckoutSession($sessionArray);
            if ($paymentObject) {
                $payments->handleSuccessfulPaymentObject($paymentObject);
            }
        }

        $freshOrder = $this->loadOrderForResponse($order->fresh());

        return response()->json([
            'order' => $freshOrder,
            'payment' => $freshOrder?->stripePayment,
            'is_paid' => $freshOrder?->status === 'paid',
        ]);
    }

    private function buildStripeLineItem(OrderItem $item, string $currencyIso): array
    {
        $typeLabel = match ($item->purchasable_type) {
            \App\Models\Service::class => 'Service booking',
            \App\Models\Product::class => 'Product',
            \App\Models\Listing::class => 'Listing',
            default => 'Item',
        };

        $name = trim(($item->title_snapshot ?: ('Item #' . $item->id)));
        $description = $this->buildStripeLineItemDescription($item, $currencyIso);

        return [
            'price_data' => [
                'currency' => strtolower($currencyIso ?: config('stripe.currency', 'eur')),
                'product_data' => array_filter([
                    'name' => $typeLabel . ': ' . $name,
                    'description' => $description,
                    'metadata' => [
                        'order_item_id' => (string) $item->id,
                        'purchasable_type' => class_basename($item->purchasable_type),
                        'purchasable_id' => (string) $item->purchasable_id,
                    ],
                ]),
                'unit_amount' => (int) $item->unit_amount,
            ],
            'quantity' => (int) $item->quantity,
        ];
    }

    private function buildStripeLineItemDescription(OrderItem $item, string $currencyIso): string
    {
        $parts = [];

        if (!empty($item->description_snapshot)) {
            $parts[] = $this->truncateStripeText($item->description_snapshot, 110);
        }

        $parts[] = 'Unit price: ' . $this->formatMinorAmount($item->unit_amount, $currencyIso ?: config('stripe.currency', 'eur'));

        if ((int) $item->quantity > 1) {
            $parts[] = 'Quantity: ' . (int) $item->quantity;
            $parts[] = 'Line total: ' . $this->formatMinorAmount($item->gross_amount, $currencyIso ?: config('stripe.currency', 'eur'));
        }

        if ($item->serviceBooking) {
            $booking = $item->serviceBooking;
            $timezone = $booking->timezone ?: 'UTC';
            $start = Carbon::parse($booking->starts_at)->timezone($timezone);
            $end = Carbon::parse($booking->ends_at)->timezone($timezone);

            $parts[] = 'Booking: ' . $start->format('Y-m-d H:i') . ' to ' . $end->format('H:i') . ' (' . $timezone . ')';
        }

        return implode(' | ', array_filter($parts));
    }

    private function formatMinorAmount(int $amount, string $currencyIso): string
    {
        $value = number_format($amount / 100, 2, '.', '');

        return $value . ' ' . strtoupper($currencyIso);
    }

    private function truncateStripeText(string $text, int $limit): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if (mb_strlen($normalized) <= $limit) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, $limit - 3)) . '...';
    }

    private function loadOrderForResponse(?Order $order): ?Order
    {
        if (!$order) {
            return null;
        }

        return $order->load(['items.serviceBooking', 'items.stripeRefunds', 'stripePayment', 'stripeRefunds']);
    }
}
