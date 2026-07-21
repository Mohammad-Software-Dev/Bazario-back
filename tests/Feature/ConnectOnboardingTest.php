<?php

namespace Tests\Feature;

use App\Models\ConnectAccount;
use App\Models\Order;
use App\Models\Seller;
use App\Models\ServiceProvider;
use App\Models\StripeTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConnectOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripeClient(?array &$capture = null)
    {
        return new class($capture) extends \Stripe\StripeClient {
            public $accounts;
            public $accountLinks;
            public $balance;
            private $capture;

            public function __construct(?array &$capture = null)
            {
                $this->capture = &$capture;

                $this->accounts = new class {
                    public function create(array $params)
                    {
                        return (object) [
                            'id' => 'acct_test_123',
                            'charges_enabled' => false,
                            'payouts_enabled' => false,
                            'details_submitted' => false,
                            'requirements' => [],
                        ];
                    }

                    public function retrieve(string $accountId, array $params = [])
                    {
                        return (object) [
                            'id' => $accountId,
                            'charges_enabled' => true,
                            'payouts_enabled' => false,
                            'details_submitted' => true,
                            'requirements' => [],
                        ];
                    }
                };

                $capture = &$this->capture;

                $this->accountLinks = new class($capture) {
                    private $capture;

                    public function __construct(?array &$capture = null)
                    {
                        $this->capture = &$capture;
                    }

                    public function create(array $params)
                    {
                        if (is_array($this->capture)) {
                            $this->capture['account_link_params'] = $params;
                        }

                        return (object) [
                            'url' => 'https://connect.stripe.test/onboard',
                            'expires_at' => now()->addHour()->timestamp,
                        ];
                    }
                };

                $this->balance = new class {
                    public function retrieve(array $params = [], array $opts = [])
                    {
                        return (object) [
                            'available' => [
                                (object) ['amount' => 4200, 'currency' => 'eur'],
                            ],
                            'pending' => [
                                (object) ['amount' => 900, 'currency' => 'eur'],
                            ],
                        ];
                    }
                };
            }
        };
    }

    public function test_customer_cannot_start_connect_onboarding(): void
    {
        Role::create(['name' => 'customer']);

        $user = User::factory()->create();
        $user->assignRole('customer');
        Sanctum::actingAs($user);

        $this->postJson('/api/connect/onboard')->assertStatus(403);
    }

    public function test_seller_can_start_connect_onboarding_and_persists_account(): void
    {
        Role::create(['name' => 'seller']);

        $user = User::factory()->create();
        $user->assignRole('seller');

        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient());

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/connect/onboard');
        $response->assertStatus(200)
            ->assertJsonStructure(['onboarding_url', 'expires_at', 'account']);

        $this->assertDatabaseHas('connect_accounts', [
            'user_id' => $user->id,
            'stripe_account_id' => 'acct_test_123',
        ]);
    }

    public function test_seller_connect_onboarding_returns_to_frontend_account_page(): void
    {
        Role::create(['name' => 'seller']);

        $user = User::factory()->create([
            'email' => 'seller-home-return@example.com',
        ]);
        $user->assignRole('seller');

        $capture = [];
        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient($capture));

        Sanctum::actingAs($user);

        $this->postJson('/api/connect/onboard')->assertStatus(200);

        $this->assertSame(
            rtrim(config('stripe.frontend_url'), '/') . '/account/stripe',
            $capture['account_link_params']['return_url'] ?? null,
        );
    }

    public function test_pending_seller_request_can_start_connect_onboarding(): void
    {
        Role::create(['name' => 'customer']);

        $user = User::factory()->create([
            'email' => 'pending-seller@example.com',
        ]);
        $user->assignRole('customer');

        Seller::create([
            'user_id' => $user->id,
            'store_owner_name' => 'Pending Owner',
            'store_name' => 'Pending Store',
            'address' => 'Berlin',
            'description' => 'Pending seller account',
            'status' => 'pending',
        ]);

        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient());

        Sanctum::actingAs($user);

        $this->postJson('/api/connect/onboard', [
            'account_type' => 'seller',
        ])->assertStatus(200)
            ->assertJson([
                'eligible_type' => 'seller',
            ]);
    }

    public function test_pending_service_provider_request_can_check_connect_status(): void
    {
        Role::create(['name' => 'customer']);

        $user = User::factory()->create();
        $user->assignRole('customer');

        ServiceProvider::create([
            'user_id' => $user->id,
            'name' => 'Pending Provider',
            'address' => 'Munich',
            'description' => 'Pending provider account',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/connect/status?account_type=service_provider')
            ->assertStatus(200)
            ->assertJson([
                'connected' => false,
                'eligible' => true,
                'eligible_type' => 'service_provider',
            ]);
    }

    public function test_customer_cannot_access_pending_connect_status_without_request_type_or_record(): void
    {
        Role::create(['name' => 'customer']);

        $user = User::factory()->create();
        $user->assignRole('customer');

        Sanctum::actingAs($user);

        $this->getJson('/api/connect/status')->assertStatus(403);
    }

    public function test_connect_status_returns_connected_true_when_account_exists(): void
    {
        Role::create(['name' => 'seller']);

        $user = User::factory()->create();
        $user->assignRole('seller');

        ConnectAccount::create([
            'user_id' => $user->id,
            'stripe_account_id' => 'acct_existing_1',
            'type' => 'express',
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/connect/status')
            ->assertStatus(200)
            ->assertJson([
                'connected' => true,
            ]);
    }

    public function test_connect_status_syncs_account_flags_from_stripe(): void
    {
        Role::create(['name' => 'seller']);

        $user = User::factory()->create();
        $user->assignRole('seller');

        ConnectAccount::create([
            'user_id' => $user->id,
            'stripe_account_id' => 'acct_sync_1',
            'type' => 'express',
            'charges_enabled' => false,
            'payouts_enabled' => false,
            'details_submitted' => false,
        ]);

        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient());

        Sanctum::actingAs($user);

        $this->getJson('/api/connect/status')
            ->assertStatus(200)
            ->assertJson([
                'connected' => true,
                'account' => [
                    'stripe_account_id' => 'acct_sync_1',
                    'details_submitted' => true,
                    'payouts_enabled' => false,
                ],
            ]);

        $this->assertDatabaseHas('connect_accounts', [
            'user_id' => $user->id,
            'stripe_account_id' => 'acct_sync_1',
            'details_submitted' => true,
        ]);
    }

    public function test_connect_summary_returns_stripe_balance_and_transfer_history(): void
    {
        Role::create(['name' => 'seller']);

        $seller = User::factory()->create();
        $seller->assignRole('seller');

        ConnectAccount::create([
            'user_id' => $seller->id,
            'stripe_account_id' => 'acct_summary_1',
            'type' => 'express',
            'charges_enabled' => true,
            'payouts_enabled' => false,
            'details_submitted' => true,
        ]);

        $buyer = User::factory()->create();
        $order = Order::create([
            'buyer_id' => $buyer->id,
            'status' => 'paid',
            'currency_iso' => 'EUR',
            'subtotal_amount' => 3000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 3000,
            'transfer_group' => 'order_summary_1',
        ]);

        StripeTransfer::create([
            'order_id' => $order->id,
            'payee_user_id' => $seller->id,
            'transfer_id' => 'tr_summary_1',
            'amount' => 2700,
            'currency_iso' => 'EUR',
            'status' => 'paid',
            'metadata' => [],
        ]);

        \App\Models\WalletLedgerEntry::create([
            'user_id' => $seller->id,
            'order_id' => $order->id,
            'order_item_id' => null,
            'type' => 'sale_pending',
            'amount' => 600,
            'currency_iso' => 'EUR',
            'available_on' => now()->subMinute(),
            'metadata' => [],
        ]);

        $this->app->instance(\Stripe\StripeClient::class, $this->fakeStripeClient());

        Sanctum::actingAs($seller);

        $this->getJson('/api/connect/summary')
            ->assertStatus(200)
            ->assertJson([
                'connected' => true,
                'account' => [
                    'stripe_account_id' => 'acct_summary_1',
                    'details_submitted' => true,
                ],
                'stripe_balance' => [
                    'available' => [
                        ['currency_iso' => 'EUR', 'amount' => 4200],
                    ],
                    'pending' => [
                        ['currency_iso' => 'EUR', 'amount' => 900],
                    ],
                ],
                'platform_pending_balance' => [
                    ['currency_iso' => 'EUR', 'amount' => 600],
                ],
                'transfers' => [
                    [
                        'transfer_id' => 'tr_summary_1',
                        'amount' => 2700,
                        'currency_iso' => 'EUR',
                    ],
                ],
            ]);
    }
}
