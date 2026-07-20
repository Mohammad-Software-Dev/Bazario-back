<?php

namespace App\Jobs;

use App\Models\ConnectAccount;
use App\Models\StripeWebhookEvent;
use App\Services\StripeOrderPaymentService;
use App\Services\StripeRefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessStripeEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $event;

    public function __construct(array $event)
    {
        $this->event = $event;
    }

    public function handle(StripeOrderPaymentService $payments, StripeRefundService $refunds): void
    {
        $eventId = $this->event['id'] ?? null;
        $eventType = $this->event['type'] ?? null;

        if (!$eventId || !$eventType) {
            return;
        }

        DB::transaction(function () use ($eventId, $eventType, $payments, $refunds) {
            $row = StripeWebhookEvent::where('event_id', $eventId)->lockForUpdate()->first();

            if (!$row || $row->processed_at) {
                return;
            }

            match ($eventType) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($payments),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($payments),
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($payments),
                'account.updated' => $this->handleAccountUpdated(),
                'refund.updated' => $this->handleRefundUpdated($refunds),
                'charge.refunded' => $this->handleChargeRefunded($refunds),
                default => null,
            };

            $row->processed_at = now();
            $row->save();
        });
    }

    private function handlePaymentIntentSucceeded(StripeOrderPaymentService $payments): void
    {
        $pi = $this->event['data']['object'] ?? null;
        if (!$pi) return;
        $payments->handleSuccessfulPaymentObject($pi);
    }

    private function handlePaymentIntentFailed(StripeOrderPaymentService $payments): void
    {
        $pi = $this->event['data']['object'] ?? null;
        if (!$pi) return;
        $payments->handleFailedPaymentObject($pi);
    }

    private function handleCheckoutSessionCompleted(StripeOrderPaymentService $payments): void
    {
        $session = $this->event['data']['object'] ?? null;
        if (!$session) return;

        $paymentObject = $payments->buildPaymentObjectFromCheckoutSession($session);
        if (!$paymentObject) return;

        if (($session['payment_status'] ?? null) === 'paid') {
            $payments->handleSuccessfulPaymentObject($paymentObject);
        }
    }

    private function handleRefundUpdated(StripeRefundService $refunds): void
    {
        $refund = $this->event['data']['object'] ?? null;
        if (!$refund) {
            return;
        }

        $refunds->syncRefundFromWebhook($refund);
    }

    private function handleChargeRefunded(StripeRefundService $refunds): void
    {
        $charge = $this->event['data']['object'] ?? null;
        $refundRows = $charge['refunds']['data'] ?? [];

        foreach ($refundRows as $refundRow) {
            $refunds->syncRefundFromWebhook($refundRow);
        }
    }

    private function handleAccountUpdated(): void
    {
        $acct = $this->event['data']['object'] ?? null;
        if (!$acct) return;

        $stripeAccountId = $acct['id'] ?? null;
        if (!$stripeAccountId) return;

        $chargesEnabled = (bool)($acct['charges_enabled'] ?? false);
        $payoutsEnabled = (bool)($acct['payouts_enabled'] ?? false);
        $detailsSubmitted = (bool)($acct['details_submitted'] ?? false);

        ConnectAccount::where('stripe_account_id', $stripeAccountId)->update([
            'charges_enabled' => $chargesEnabled,
            'payouts_enabled' => $payoutsEnabled,
            'details_submitted' => $detailsSubmitted,
            'onboarding_completed_at' => ($chargesEnabled && $payoutsEnabled) ? now() : null,
            'requirements' => $acct['requirements'] ?? null,
        ]);
    }
}
