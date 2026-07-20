<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StripePayment;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;

class StripeOrderPaymentService
{
    public function handleSuccessfulPaymentObject(array $paymentObject): ?Order
    {
        $orderId = $paymentObject['metadata']['order_id'] ?? null;
        if (!$orderId) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $paymentObject) {
            /** @var Order|null $order */
            $order = Order::with(['items', 'items.serviceBooking'])->whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return null;
            }

            $existingPayment = StripePayment::query()->where('order_id', $order->id)->first();

            StripePayment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_intent_id' => (string) ($paymentObject['id'] ?? ('pi_missing_' . $order->id)),
                    'status' => (string) ($paymentObject['status'] ?? 'succeeded'),
                    'charge_id' => $paymentObject['latest_charge'] ?? $existingPayment?->charge_id,
                    'amount' => (int) ($paymentObject['amount'] ?? $order->total_amount),
                    'currency_iso' => strtoupper((string) ($paymentObject['currency'] ?? $order->currency_iso)),
                    'metadata' => $paymentObject,
                ]
            );

            if (!$order->paid_at) {
                $order->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);

                foreach ($order->items as $item) {
                    $exists = WalletLedgerEntry::where('order_item_id', $item->id)
                        ->where('type', 'sale_pending')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $availableOn = $item->serviceBooking?->ends_at ?? now();

                    WalletLedgerEntry::create([
                        'user_id' => $item->payee_user_id,
                        'order_id' => $order->id,
                        'order_item_id' => $item->id,
                        'type' => 'sale_pending',
                        'amount' => (int) $item->net_amount,
                        'currency_iso' => $order->currency_iso,
                        'available_on' => $availableOn,
                        'metadata' => [
                            'payment_intent_id' => $paymentObject['id'] ?? null,
                            'gross_amount' => (int) $item->gross_amount,
                            'platform_fee_amount' => (int) $item->platform_fee_amount,
                            'requires_booking_completion' => (bool) $item->serviceBooking,
                        ],
                    ]);
                }
            }

            return $order->fresh(['items', 'items.serviceBooking', 'stripePayment']);
        });
    }

    public function handleFailedPaymentObject(array $paymentObject): ?Order
    {
        $orderId = $paymentObject['metadata']['order_id'] ?? null;
        if (!$orderId) {
            return null;
        }

        return DB::transaction(function () use ($orderId, $paymentObject) {
            /** @var Order|null $order */
            $order = Order::whereKey($orderId)->lockForUpdate()->first();
            if (!$order) {
                return null;
            }

            $existingPayment = StripePayment::query()->where('order_id', $order->id)->first();

            StripePayment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'payment_intent_id' => (string) ($paymentObject['id'] ?? ('pi_missing_' . $order->id)),
                    'status' => (string) ($paymentObject['status'] ?? 'failed'),
                    'charge_id' => $paymentObject['latest_charge'] ?? $existingPayment?->charge_id,
                    'amount' => (int) ($paymentObject['amount'] ?? $order->total_amount),
                    'currency_iso' => strtoupper((string) ($paymentObject['currency'] ?? $order->currency_iso)),
                    'metadata' => $paymentObject,
                ]
            );

            if (!$order->paid_at) {
                $order->update([
                    'status' => 'requires_payment',
                ]);
            }

            return $order->fresh(['items', 'items.serviceBooking', 'stripePayment']);
        });
    }

    public function buildPaymentObjectFromCheckoutSession(array $session): ?array
    {
        $orderId = $session['metadata']['order_id'] ?? null;
        if (!$orderId) {
            return null;
        }

        return [
            'id' => $session['payment_intent'] ?? ('cs_' . ($session['id'] ?? $orderId)),
            'status' => ($session['payment_status'] ?? null) === 'paid'
                ? 'succeeded'
                : (string) ($session['status'] ?? 'complete'),
            'latest_charge' => null,
            'amount' => (int) ($session['amount_total'] ?? 0),
            'currency' => (string) ($session['currency'] ?? 'eur'),
            'metadata' => [
                'order_id' => (string) $orderId,
            ],
        ];
    }
}
