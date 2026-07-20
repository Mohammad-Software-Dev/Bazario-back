<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceBooking;
use App\Models\StripeRefund;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class StripeRefundService
{
    public function __construct(private StripeClient $stripe)
    {
    }

    public function refundBookingCancellation(ServiceBooking $booking, string $reason = 'booking_cancelled'): ?StripeRefund
    {
        $booking->loadMissing(['orderItem.order.stripePayment', 'orderItem.stripeRefunds']);

        $orderItem = $booking->orderItem;
        $order = $orderItem?->order;
        $payment = $order?->stripePayment;

        if (!$orderItem || !$order || !$payment) {
            return null;
        }

        $existingRefund = StripeRefund::query()
            ->where('service_booking_id', $booking->id)
            ->whereNotIn('status', ['failed', 'canceled'])
            ->latest('id')
            ->first();

        if ($existingRefund) {
            return DB::transaction(function () use ($booking, $orderItem, $order, $existingRefund) {
                $this->applyRefundState($booking, $orderItem, $order, $existingRefund);

                return $existingRefund->fresh();
            });
        }

        $amount = (int) $orderItem->gross_amount;
        if ($amount <= 0) {
            return null;
        }

        $refund = $this->stripe->refunds->create([
            'payment_intent' => $payment->payment_intent_id,
            'amount' => $amount,
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_item_id' => (string) $orderItem->id,
                'service_booking_id' => (string) $booking->id,
                'reason' => $reason,
            ],
        ], [
            'idempotency_key' => $this->makeRefundIdempotencyKey($booking),
        ]);

        $refundData = $refund instanceof \Stripe\StripeObject ? $refund->toArray() : (array) $refund;

        return DB::transaction(function () use ($booking, $orderItem, $order, $payment, $refundData, $reason) {
            $refundModel = StripeRefund::create([
                'order_id' => $order->id,
                'order_item_id' => $orderItem->id,
                'service_booking_id' => $booking->id,
                'stripe_payment_id' => $payment->id,
                'payment_intent_id' => (string) ($refundData['payment_intent'] ?? $payment->payment_intent_id),
                'charge_id' => $refundData['charge'] ?? $payment->charge_id,
                'stripe_refund_id' => (string) ($refundData['id'] ?? ''),
                'amount' => (int) ($refundData['amount'] ?? $orderItem->gross_amount),
                'currency_iso' => strtoupper((string) ($refundData['currency'] ?? $payment->currency_iso ?? $order->currency_iso)),
                'status' => (string) ($refundData['status'] ?? 'pending'),
                'reason' => $reason,
                'metadata' => $refundData,
            ]);

            $this->applyRefundState($booking, $orderItem, $order, $refundModel);

            return $refundModel->fresh();
        });
    }

    public function syncRefundFromWebhook(array $refundData): ?StripeRefund
    {
        $stripeRefundId = (string) ($refundData['id'] ?? '');
        if ($stripeRefundId === '') {
            return null;
        }

        $metadata = $refundData['metadata'] ?? [];
        $orderId = isset($metadata['order_id']) ? (int) $metadata['order_id'] : null;
        $orderItemId = isset($metadata['order_item_id']) ? (int) $metadata['order_item_id'] : null;
        $serviceBookingId = isset($metadata['service_booking_id']) ? (int) $metadata['service_booking_id'] : null;

        $paymentId = null;
        if (!empty($refundData['payment_intent'])) {
            $paymentId = optional(
                \App\Models\StripePayment::query()->where('payment_intent_id', $refundData['payment_intent'])->first()
            )->id;
        }

        $refund = DB::transaction(function () use ($stripeRefundId, $refundData, $metadata, $orderId, $orderItemId, $serviceBookingId, $paymentId) {
            return StripeRefund::updateOrCreate(
                ['stripe_refund_id' => $stripeRefundId],
                [
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'service_booking_id' => $serviceBookingId,
                    'stripe_payment_id' => $paymentId,
                    'payment_intent_id' => $refundData['payment_intent'] ?? null,
                    'charge_id' => $refundData['charge'] ?? null,
                    'amount' => (int) ($refundData['amount'] ?? 0),
                    'currency_iso' => strtoupper((string) ($refundData['currency'] ?? 'EUR')),
                    'status' => (string) ($refundData['status'] ?? 'pending'),
                    'reason' => (string) ($metadata['reason'] ?? 'stripe_webhook'),
                    'metadata' => $refundData,
                ]
            );
        });

        if (!$orderId || !$orderItemId || !$serviceBookingId) {
            return $refund;
        }

        $booking = ServiceBooking::query()->find($serviceBookingId);
        $orderItem = OrderItem::query()->find($orderItemId);
        $order = Order::query()->find($orderId);

        if ($booking && $orderItem && $order && !in_array($refund->status, ['failed', 'canceled'], true)) {
            DB::transaction(function () use ($booking, $orderItem, $order, $refund) {
                $this->applyRefundState($booking, $orderItem, $order, $refund);
            });
        }

        return $refund;
    }

    public function applyRefundState(ServiceBooking $booking, OrderItem $orderItem, Order $order, StripeRefund $refund): void
    {
        if ($orderItem->status !== 'refunded') {
            $orderItem->status = 'refunded';
            $orderItem->save();
        }

        $this->reversePendingEarnings($orderItem, $refund);
        $this->refreshOrderRefundStatus($order);
    }

    private function reversePendingEarnings(OrderItem $orderItem, StripeRefund $refund): void
    {
        $entries = WalletLedgerEntry::query()
            ->where('order_item_id', $orderItem->id)
            ->whereIn('type', ['sale_pending', 'transfer_pending'])
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            $existingReversal = WalletLedgerEntry::query()
                ->where('order_item_id', $orderItem->id)
                ->whereIn('type', ['sale_refund', 'transfer_reversal'])
                ->where('metadata->original_entry_id', $entry->id)
                ->exists();

            if (!$existingReversal) {
                WalletLedgerEntry::create([
                    'user_id' => $entry->user_id,
                    'order_id' => $entry->order_id,
                    'order_item_id' => $entry->order_item_id,
                    'type' => $entry->type === 'sale_pending' ? 'sale_refund' : 'transfer_reversal',
                    'amount' => -abs((int) $entry->amount),
                    'currency_iso' => $entry->currency_iso,
                    'available_on' => now(),
                    'metadata' => array_filter([
                        'original_entry_id' => $entry->id,
                        'stripe_refund_id' => $refund->stripe_refund_id,
                    ]),
                ]);
            }

            $metadata = $entry->metadata ?? [];
            $metadata['reversed_by_refund_id'] = $refund->stripe_refund_id;

            $entry->update([
                'type' => $entry->type === 'sale_pending' ? 'sale_reversed' : 'transfer_reversed',
                'metadata' => $metadata,
            ]);
        }
    }

    private function refreshOrderRefundStatus(Order $order): void
    {
        $order->loadMissing('items');

        $totalItems = $order->items->count();
        $refundedItems = $order->items->where('status', 'refunded')->count();

        if ($refundedItems === 0) {
            return;
        }

        $nextStatus = $refundedItems === $totalItems ? 'refunded' : 'partially_refunded';

        if ($order->status !== $nextStatus) {
            $order->update([
                'status' => $nextStatus,
            ]);
        }
    }

    private function makeRefundIdempotencyKey(ServiceBooking $booking): string
    {
        return 'booking_refund_' . sha1((string) $booking->id);
    }
}
