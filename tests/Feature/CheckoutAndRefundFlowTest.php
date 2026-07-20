<?php

namespace Tests\Feature;

use App\Models\ConnectAccount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Service;
use App\Models\ServiceBooking;
use App\Models\ServiceProvider;
use App\Models\ServiceProviderWorkingHour;
use App\Models\StripeRefund;
use App\Models\StripeTransfer;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\StripeOrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CheckoutAndRefundFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'stripe.webhook_secret' => 'whsec_test_local',
            'queue.default' => 'sync',
        ]);
    }

    public function test_checkout_session_creation_returns_stable_contract(): void
    {
        $buyer = User::factory()->create();
        Sanctum::actingAs($buyer);

        $order = Order::create([
            'buyer_id' => $buyer->id,
            'status' => 'draft',
            'currency_iso' => 'EUR',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 5000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'purchasable_type' => 'App\\Models\\Product',
            'purchasable_id' => 1,
            'title_snapshot' => 'Checkout Product',
            'description_snapshot' => 'Product description',
            'quantity' => 1,
            'unit_amount' => 5000,
            'gross_amount' => 5000,
            'platform_fee_amount' => 500,
            'net_amount' => 4500,
            'payee_user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture, [
            'checkout_create' => [
                'id' => 'cs_checkout_contract_1',
                'url' => 'https://checkout.stripe.test/session/cs_checkout_contract_1',
            ],
        ]));

        $response = $this->postJson("/api/orders/{$order->id}/checkout-session");

        $response->assertStatus(200)
            ->assertJson([
                'checkout_url' => 'https://checkout.stripe.test/session/cs_checkout_contract_1',
                'checkout_session_id' => 'cs_checkout_contract_1',
                'order_id' => $order->id,
                'status' => 'requires_payment',
            ]);

        $this->assertSame((string) $order->id, $capture['checkout_create']['metadata']['order_id'] ?? null);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'requires_payment',
        ]);
    }

    public function test_checkout_session_reconcile_returns_paid_contract(): void
    {
        $buyer = User::factory()->create();
        $payee = User::factory()->create();
        Sanctum::actingAs($buyer);

        $order = Order::create([
            'buyer_id' => $buyer->id,
            'status' => 'requires_payment',
            'currency_iso' => 'EUR',
            'subtotal_amount' => 5000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'transfer_group' => 'order_reconcile_1',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'purchasable_type' => 'App\\Models\\Product',
            'purchasable_id' => 1,
            'title_snapshot' => 'Checkout Product',
            'description_snapshot' => 'Product description',
            'quantity' => 1,
            'unit_amount' => 5000,
            'gross_amount' => 5000,
            'platform_fee_amount' => 500,
            'net_amount' => 4500,
            'payee_user_id' => $payee->id,
            'status' => 'pending',
        ]);

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture, [
            'checkout_retrieve' => [
                'id' => 'cs_checkout_contract_2',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_checkout_contract_2',
                'amount_total' => 5000,
                'currency' => 'eur',
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
            ],
        ]));

        $response = $this->postJson("/api/orders/{$order->id}/checkout-session/reconcile", [
            'session_id' => 'cs_checkout_contract_2',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('is_paid', true)
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('payment.payment_intent_id', 'pi_checkout_contract_2');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'order_id' => $order->id,
            'order_item_id' => $order->items()->first()->id,
            'type' => 'sale_pending',
            'amount' => 4500,
        ]);
    }

    public function test_customer_cancellation_creates_refund_and_reverses_pending_earnings(): void
    {
        [$customer, $providerUser, $booking, $order, $orderItem] = $this->makePaidServiceBooking();

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture, [
            'refund_create' => [
                'id' => 're_booking_customer_1',
                'payment_intent' => 'pi_booking_payment_1',
                'charge' => 'ch_booking_payment_1',
                'amount' => 10000,
                'currency' => 'eur',
                'status' => 'succeeded',
            ],
        ]));

        Sanctum::actingAs($customer);

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'Need to cancel',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('booking.status', 'cancelled_by_customer')
            ->assertJsonPath('refund.applied', true)
            ->assertJsonPath('refund.amount', 10000)
            ->assertJsonPath('refund.status', 'succeeded')
            ->assertJsonPath('order_status', 'refunded');

        $this->assertSame('pi_booking_payment_1', $capture['refund_create']['payment_intent'] ?? null);
        $this->assertDatabaseHas('stripe_refunds', [
            'service_booking_id' => $booking->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'stripe_refund_id' => 're_booking_customer_1',
            'amount' => 10000,
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'status' => 'refunded',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'refunded',
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'order_item_id' => $orderItem->id,
            'type' => 'sale_reversed',
            'amount' => 9000,
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'order_item_id' => $orderItem->id,
            'type' => 'sale_refund',
            'amount' => -9000,
        ]);
    }

    public function test_provider_cancellation_creates_refund_for_paid_booking(): void
    {
        [$customer, $providerUser, $booking] = $this->makePaidServiceBooking();

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture, [
            'refund_create' => [
                'id' => 're_booking_provider_1',
                'payment_intent' => 'pi_booking_payment_1',
                'charge' => 'ch_booking_payment_1',
                'amount' => 10000,
                'currency' => 'eur',
                'status' => 'succeeded',
            ],
        ]));

        Sanctum::actingAs($providerUser);

        $response = $this->patchJson("/api/bookings/{$booking->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('booking.status', 'cancelled_by_provider')
            ->assertJsonPath('refund.applied', true)
            ->assertJsonPath('refund.stripe_refund_id', 're_booking_provider_1');
    }

    public function test_refund_webhook_sync_is_idempotent(): void
    {
        [$customer, $providerUser, $booking] = $this->makePaidServiceBooking();

        $refund = StripeRefund::create([
            'order_id' => $booking->orderItem->order_id,
            'order_item_id' => $booking->order_item_id,
            'service_booking_id' => $booking->id,
            'stripe_payment_id' => $booking->orderItem->order->stripePayment->id,
            'payment_intent_id' => 'pi_booking_payment_1',
            'charge_id' => 'ch_booking_payment_1',
            'stripe_refund_id' => 're_webhook_sync_1',
            'amount' => 10000,
            'currency_iso' => 'EUR',
            'status' => 'pending',
            'reason' => 'customer_cancellation',
            'metadata' => [],
        ]);

        $event = [
            'id' => 'evt_refund_sync_1',
            'type' => 'refund.updated',
            'data' => [
                'object' => [
                    'id' => 're_webhook_sync_1',
                    'payment_intent' => 'pi_booking_payment_1',
                    'charge' => 'ch_booking_payment_1',
                    'amount' => 10000,
                    'currency' => 'eur',
                    'status' => 'succeeded',
                    'metadata' => [
                        'order_id' => (string) $booking->orderItem->order_id,
                        'order_item_id' => (string) $booking->order_item_id,
                        'service_booking_id' => (string) $booking->id,
                        'reason' => 'customer_cancellation',
                    ],
                ],
            ],
        ];

        $this->postSignedStripeWebhook($event)->assertOk();

        $this->assertSame(1, StripeRefund::where('stripe_refund_id', 're_webhook_sync_1')->count());
        $this->assertDatabaseHas('stripe_refunds', [
            'stripe_refund_id' => 're_webhook_sync_1',
            'status' => 'succeeded',
        ]);
    }

    public function test_booking_earnings_are_not_payable_until_completion(): void
    {
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'service_provider']);

        [$customer, $providerUser, $booking, $order, $orderItem, $provider] = $this->makePaidServiceBooking(returnProvider: true);
        $providerUser->assignRole('service_provider');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        ConnectAccount::create([
            'user_id' => $providerUser->id,
            'stripe_account_id' => 'acct_provider_test_1',
            'type' => 'express',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]);

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture, [
            'transfer_create' => [
                'id' => 'tr_test_booking_1',
                'status' => 'paid',
            ],
        ]));

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payouts/{$providerUser->id}/pay")
            ->assertStatus(422);

        Sanctum::actingAs($providerUser);
        $this->patchJson("/api/bookings/{$booking->id}/complete")
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed');

        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/payouts/{$providerUser->id}/pay")
            ->assertStatus(200)
            ->assertJsonPath('transfers.0.transfer_id', 'tr_test_booking_1');

        $this->assertDatabaseHas('stripe_transfers', [
            'order_id' => $order->id,
            'payee_user_id' => $providerUser->id,
            'transfer_id' => 'tr_test_booking_1',
        ]);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'order_item_id' => $orderItem->id,
            'type' => 'transfer_out',
        ]);
    }

    private function makePaidServiceBooking(bool $returnProvider = false): array
    {
        $customer = User::factory()->create();
        $providerUser = User::factory()->create();
        $provider = ServiceProvider::factory()->create([
            'user_id' => $providerUser->id,
            'timezone' => 'UTC',
        ]);

        $start = now('UTC')->addDays(2)->setTime(10, 0);
        $end = $start->copy()->addHour();

        ServiceProviderWorkingHour::create([
            'service_provider_id' => $provider->id,
            'day_of_week' => $start->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '18:00',
        ]);

        $service = Service::factory()->create([
            'provider_id' => $provider->id,
            'is_active' => true,
            'price' => 100,
            'currency_iso' => 'EUR',
            'cancel_cutoff_hours' => 0,
            'cancel_late_policy' => 'allow',
        ]);

        $order = Order::create([
            'buyer_id' => $customer->id,
            'status' => 'requires_payment',
            'currency_iso' => 'EUR',
            'subtotal_amount' => 10000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 10000,
            'transfer_group' => 'order_booking_' . uniqid(),
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'purchasable_type' => Service::class,
            'purchasable_id' => $service->id,
            'title_snapshot' => 'Consulting session',
            'description_snapshot' => 'Booked service',
            'quantity' => 1,
            'unit_amount' => 10000,
            'gross_amount' => 10000,
            'platform_fee_amount' => 1000,
            'net_amount' => 9000,
            'payee_user_id' => $providerUser->id,
            'status' => 'pending',
        ]);

        $booking = ServiceBooking::create([
            'order_item_id' => $orderItem->id,
            'service_id' => $service->id,
            'provider_user_id' => $providerUser->id,
            'customer_user_id' => $customer->id,
            'status' => 'confirmed',
            'starts_at' => $start,
            'ends_at' => $end,
            'timezone' => 'UTC',
            'location_type' => 'online',
        ]);

        app(StripeOrderPaymentService::class)->handleSuccessfulPaymentObject([
            'id' => 'pi_booking_payment_1',
            'status' => 'succeeded',
            'latest_charge' => 'ch_booking_payment_1',
            'amount' => 10000,
            'currency' => 'eur',
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
        ]);

        $payload = [$customer, $providerUser, $booking->fresh(['orderItem.order.stripePayment']), $order->fresh(), $orderItem->fresh()];

        if ($returnProvider) {
            $payload[] = $provider;
        }

        return $payload;
    }

    private function fakeStripeClient(?array &$capture = null, array $state = [])
    {
        return new class($capture, $state) extends \Stripe\StripeClient {
            public $checkout;
            public $refunds;
            public $transfers;
            private $capture;
            private $state;

            public function __construct(?array &$capture = null, array $state = [])
            {
                $this->capture = &$capture;
                $this->state = $state;
                $capture = &$this->capture;
                $stateRef = $this->state;

                $this->checkout = new class($capture, $stateRef) {
                    public $sessions;

                    public function __construct(?array &$capture = null, array $state = [])
                    {
                        $captureRef = &$capture;
                        $stateRef = $state;

                        $this->sessions = new class($captureRef, $stateRef) {
                            private $capture;
                            private $state;

                            public function __construct(?array &$capture = null, array $state = [])
                            {
                                $this->capture = &$capture;
                                $this->state = $state;
                            }

                            public function create(array $params, array $opts = [])
                            {
                                if (is_array($this->capture)) {
                                    $this->capture['checkout_create'] = $params;
                                }

                                $session = $this->state['checkout_create'] ?? [
                                    'id' => 'cs_default_test',
                                    'url' => 'https://checkout.stripe.test/default',
                                ];

                                return (object) $session;
                            }

                            public function retrieve(string $sessionId, array $params = [])
                            {
                                $session = $this->state['checkout_retrieve'] ?? [
                                    'id' => $sessionId,
                                    'payment_status' => 'paid',
                                    'payment_intent' => 'pi_default_test',
                                    'amount_total' => 5000,
                                    'currency' => 'eur',
                                    'metadata' => [],
                                ];

                                return (object) $session;
                            }
                        };
                    }
                };

                $this->refunds = new class($capture, $stateRef) {
                    private $capture;
                    private $state;

                    public function __construct(?array &$capture = null, array $state = [])
                    {
                        $this->capture = &$capture;
                        $this->state = $state;
                    }

                    public function create(array $params, array $opts = [])
                    {
                        if (is_array($this->capture)) {
                            $this->capture['refund_create'] = $params;
                        }

                        $refund = $this->state['refund_create'] ?? [
                            'id' => 're_default_test',
                            'payment_intent' => $params['payment_intent'] ?? 'pi_default_test',
                            'charge' => 'ch_default_test',
                            'amount' => $params['amount'] ?? 0,
                            'currency' => 'eur',
                            'status' => 'succeeded',
                            'metadata' => $params['metadata'] ?? [],
                        ];

                        return (object) $refund;
                    }
                };

                $this->transfers = new class($capture, $stateRef) {
                    private $capture;
                    private $state;

                    public function __construct(?array &$capture = null, array $state = [])
                    {
                        $this->capture = &$capture;
                        $this->state = $state;
                    }

                    public function create(array $params, array $opts = [])
                    {
                        if (is_array($this->capture)) {
                            $this->capture['transfer_create'] = $params;
                        }

                        $transfer = $this->state['transfer_create'] ?? [
                            'id' => 'tr_default_test',
                            'status' => 'paid',
                        ];

                        return (object) $transfer;
                    }
                };
            }
        };
    }

    private function postSignedStripeWebhook(array $event)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $payload,
            (string) config('stripe.webhook_secret')
        );

        return $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            $payload
        );
    }
}
