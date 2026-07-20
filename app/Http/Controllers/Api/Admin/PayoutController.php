<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\StripeTransfer;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

class PayoutController extends Controller
{
    private const PAYABLE_LEDGER_TYPES = ['sale_pending', 'transfer_pending'];

    public function index()
    {
        $users = User::query()
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['seller', 'service_provider']);
            })
            ->with(['connectAccount', 'roles'])
            ->get(['id', 'name', 'email']);

        $balances = $this->payableLedgerEntriesQuery()
            ->select('user_id', 'currency_iso', DB::raw('SUM(amount) as amount'))
            ->groupBy('user_id', 'currency_iso')
            ->get();

        $balanceMap = [];
        foreach ($balances as $row) {
            $balanceMap[$row->user_id][] = [
                'currency_iso' => $row->currency_iso,
                'amount' => (int) $row->amount,
            ];
        }

        $data = $users->map(function ($user) use ($balanceMap) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->values(),
                'balances' => $balanceMap[$user->id] ?? [],
                'connect_account' => $user->connectAccount,
            ];
        });

        return response()->json([
            'result' => $data,
        ]);
    }

    public function payUser(Request $request, User $user, StripeClient $stripe)
    {
        $results = $this->releaseFundsToStripeAccount($user, $stripe);

        return response()->json([
            'message' => 'Transfer initiated.',
            'transfers' => $results,
        ]);
    }

    public function payAll(Request $request, StripeClient $stripe)
    {
        $users = User::query()
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['seller', 'service_provider']);
            })
            ->get();

        $processed = [];
        foreach ($users as $user) {
            try {
                $result = $this->releaseFundsToStripeAccount($user, $stripe);
                $processed[] = [
                    'user_id' => $user->id,
                    'status' => 'ok',
                    'transfers' => $result,
                ];
            } catch (\Throwable $e) {
                $processed[] = [
                    'user_id' => $user->id,
                    'status' => 'skipped',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Payout batch completed.',
            'results' => $processed,
        ]);
    }

    private function releaseFundsToStripeAccount(User $user, StripeClient $stripe): array
    {
        $user->load(['connectAccount', 'roles']);

        if (!$user->hasAnyRole(['seller', 'service_provider'])) {
            abort(422, 'User is not eligible to receive transfers.');
        }

        $account = $user->connectAccount;
        if (!$account) {
            abort(422, 'User does not have a connected account.');
        }

        if (!$account->payouts_enabled) {
            abort(422, 'Connected account is not payout-enabled.');
        }

        $batches = $this->reserveTransferBatches($user);
        $results = [];

        foreach ($batches as $batch) {
            $order = Order::query()->find($batch['order_id']);
            $transferGroup = $order?->transfer_group ?: ('order_' . $batch['order_id']);

            try {
                $transfer = $stripe->transfers->create([
                    'amount' => $batch['amount'],
                    'currency' => strtolower($batch['currency_iso']),
                    'destination' => $account->stripe_account_id,
                    'transfer_group' => $transferGroup,
                    'metadata' => [
                        'order_id' => (string) $batch['order_id'],
                        'payee_user_id' => (string) $user->id,
                        'transfer_batch_key' => $batch['batch_key'],
                    ],
                ], [
                    'idempotency_key' => $batch['batch_key'],
                ]);
            } catch (\Throwable $e) {
                $this->releaseReservedBatch($batch['entry_ids']);
                throw $e;
            }

            $results[] = $this->finalizeTransferBatch(
                user: $user,
                batch: $batch,
                transfer: $transfer,
                transferGroup: $transferGroup,
            );
        }

        if ($results === []) {
            abort(422, 'No available balance to payout.');
        }

        return $results;
    }

    private function reserveTransferBatches(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $entries = $this->payableLedgerEntriesQuery()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                abort(422, 'No available balance to payout.');
            }

            $groups = $entries->groupBy(function ($entry) {
                return $entry->order_id . '|' . strtoupper($entry->currency_iso);
            });

            $batches = [];

            foreach ($groups as $groupEntries) {
                $sample = $groupEntries->first();
                if (!$sample?->order_id) {
                    continue;
                }

                $amount = (int) $groupEntries->sum('amount');
                if ($amount <= 0) {
                    continue;
                }

                $batchKey = $this->makeBatchKey($user->id, $sample->order_id, strtoupper($sample->currency_iso), $groupEntries);

                foreach ($groupEntries as $entry) {
                    $metadata = $entry->metadata ?? [];
                    $currentBatchKey = $metadata['transfer_batch_key'] ?? null;
                    $metadata['transfer_batch_key'] = $batchKey;

                    if ($entry->type !== 'transfer_pending' || $currentBatchKey !== $batchKey) {
                        $entry->update([
                            'type' => 'transfer_pending',
                            'metadata' => $metadata,
                        ]);
                    }
                }

                $batches[] = [
                    'batch_key' => $batchKey,
                    'entry_ids' => $groupEntries->pluck('id')->values()->all(),
                    'order_id' => $sample->order_id,
                    'amount' => $amount,
                    'currency_iso' => strtoupper($sample->currency_iso),
                ];
            }

            if ($batches === []) {
                abort(422, 'No available balance to payout.');
            }

            return $batches;
        });
    }

    private function finalizeTransferBatch(User $user, array $batch, object $transfer, string $transferGroup): array
    {
        return DB::transaction(function () use ($user, $batch, $transfer, $transferGroup) {
            StripeTransfer::updateOrCreate(
                ['transfer_id' => $transfer->id],
                [
                    'order_id' => $batch['order_id'],
                    'payee_user_id' => $user->id,
                    'amount' => $batch['amount'],
                    'currency_iso' => $batch['currency_iso'],
                    'status' => $transfer->status ?? 'created',
                    'metadata' => [
                        'transfer_group' => $transferGroup,
                        'transfer_batch_key' => $batch['batch_key'],
                    ],
                ],
            );

            $entries = WalletLedgerEntry::query()
                ->whereIn('id', $batch['entry_ids'])
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $metadata = $entry->metadata ?? [];
                $metadata['transfer_id'] = $transfer->id;
                $metadata['transfer_batch_key'] = $batch['batch_key'];

                $entry->update([
                    'type' => 'transfer_out',
                    'metadata' => $metadata,
                ]);
            }

            return [
                'order_id' => $batch['order_id'],
                'transfer_id' => $transfer->id,
                'amount' => $batch['amount'],
                'currency_iso' => $batch['currency_iso'],
            ];
        });
    }

    private function releaseReservedBatch(array $entryIds): void
    {
        DB::transaction(function () use ($entryIds) {
            $entries = WalletLedgerEntry::query()
                ->whereIn('id', $entryIds)
                ->where('type', 'transfer_pending')
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                $entry->update([
                    'type' => 'sale_pending',
                ]);
            }
        });
    }

    private function makeBatchKey(int $userId, int $orderId, string $currencyIso, Collection $groupEntries): string
    {
        $entryIds = $groupEntries->pluck('id')->sort()->implode('-');

        return 'transfer_' . sha1($userId . '|' . $orderId . '|' . $currencyIso . '|' . $entryIds);
    }

    private function payableLedgerEntriesQuery()
    {
        return WalletLedgerEntry::query()
            ->whereIn('type', self::PAYABLE_LEDGER_TYPES)
            ->where(function ($q) {
                $q->whereNull('available_on')->orWhere('available_on', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('order_item_id')
                    ->orWhereHas('orderItem', function ($itemQuery) {
                        $itemQuery->whereDoesntHave('serviceBooking')
                            ->orWhereHas('serviceBooking', function ($bookingQuery) {
                                $bookingQuery->where('status', 'completed');
                            });
                    });
            });
    }
}
