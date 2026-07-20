<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceBooking;
use App\Models\ServiceProvider;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        $order = Order::create([
            'buyer_id' => $user->id,
            'status' => 'draft',
            'currency_iso' => 'EUR',
            'transfer_group' => null,
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);

        return response()->json($this->loadOrderRelations($order));
    }

    public function show(Request $request, Order $order)
    {
        abort_unless($order->buyer_id === $request->user()->id, 403);

        return response()->json($this->loadOrderRelations($order));
    }

    public function myOrders(Request $request)
    {
        $user = $request->user();

        $orders = Order::query()
            ->where('buyer_id', $user->id)
            ->with(['items.serviceBooking', 'items.stripeRefunds', 'stripePayment', 'stripeRefunds'])
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($orders);
    }

    public function mySales(Request $request)
    {
        $user = $request->user();

        $query = OrderItem::query()
            ->with(['order.buyer', 'serviceBooking', 'stripeRefunds'])
            ->whereHas('order', function ($q) {
                $q->where('status', 'paid');
            })
            ->orderByDesc('id');

        if ($user->hasRole('admin') && $request->filled('user_id')) {
            $targetId = (int) $request->query('user_id');
            $target = User::query()->findOrFail($targetId);
            $query->where('payee_user_id', $target->id);
        } else {
            $query->where('payee_user_id', $user->id);
        }

        return response()->json([
            'items' => $query->get(),
        ]);
    }

    public function addItem(Request $request, Order $order)
    {
        abort_unless($order->buyer_id === $request->user()->id, 403);
        abort_if($order->status !== 'draft', 422, __('orders.not_editable'));

        $data = $request->validate([
            'type' => ['required', 'in:product,service,listing'],
            'id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'location_type' => ['nullable', 'string', 'max:32'],
            'location_payload' => ['nullable', 'array'],
        ]);

        return DB::transaction(function () use ($order, $data) {
            $feePercent = (float) Setting::getValue('platform_fee_percent', 10);
            $feeRate = max(0, min(100, $feePercent)) / 100;
            $qty = (int) ($data['quantity'] ?? 1);

            if ($data['type'] === 'product') {
                $product = Product::with('seller')->findOrFail($data['id']);

                $payeeUserId = $product->seller->user_id ?? null;
                abort_if(!$payeeUserId, 422, __('orders.seller_user_missing'));

                $unit = (int) round(((float) $product->price) * 100);
                $gross = $unit * $qty;
                $fee = (int) round($gross * $feeRate);
                $net = $gross - $fee;

                OrderItem::create([
                    'order_id' => $order->id,
                    'purchasable_type' => Product::class,
                    'purchasable_id' => $product->id,
                    'title_snapshot' => $this->snapshotText($product->name),
                    'description_snapshot' => $this->snapshotText($product->description),
                    'quantity' => $qty,
                    'unit_amount' => $unit,
                    'gross_amount' => $gross,
                    'platform_fee_amount' => $fee,
                    'net_amount' => $net,
                    'payee_user_id' => $payeeUserId,
                    'status' => 'pending',
                ]);
            }

            if ($data['type'] === 'listing') {
                $listing = Listing::findOrFail($data['id']);

                $unit = (int) round(((float) $listing->price) * 100);
                $gross = $unit * $qty;
                $fee = (int) round($gross * $feeRate);
                $net = $gross - $fee;

                OrderItem::create([
                    'order_id' => $order->id,
                    'purchasable_type' => Listing::class,
                    'purchasable_id' => $listing->id,
                    'title_snapshot' => $listing->title,
                    'description_snapshot' => $listing->description,
                    'quantity' => $qty,
                    'unit_amount' => $unit,
                    'gross_amount' => $gross,
                    'platform_fee_amount' => $fee,
                    'net_amount' => $net,
                    'payee_user_id' => $listing->user_id,
                    'status' => 'pending',
                ]);
            }

            if ($data['type'] === 'service') {
                $service = Service::with('serviceProvider')->findOrFail($data['id']);

                $providerUserId = $service->serviceProvider->user_id ?? null;
                abort_if(!$providerUserId, 422, __('orders.provider_user_missing'));

                if (!$service->is_active) {
                    abort(422, __('bookings.service_not_active'));
                }

                abort_if(empty($data['starts_at']), 422, __('orders.service_dates_required'));

                $provider = ServiceProvider::query()
                    ->whereKey($service->provider_id)
                    ->with(['workingHours', 'timeOffs'])
                    ->first();

                abort_if(!$provider, 422, __('bookings.service_provider_not_found'));

                $tz = $data['timezone'] ?? $provider->timezone ?? 'UTC';
                $startsAtUtc = Carbon::parse($data['starts_at'], $tz)->utc();

                if ($startsAtUtc->lessThanOrEqualTo(Carbon::now('UTC'))) {
                    abort(422, __('bookings.start_time_in_past'));
                }

                if (!empty($data['ends_at'])) {
                    $endsAtUtc = Carbon::parse($data['ends_at'], $tz)->utc();
                } else {
                    $duration = (int) ($service->duration_minutes ?? 60);
                    $endsAtUtc = $startsAtUtc->copy()->addMinutes($duration);
                }

                if ($endsAtUtc->lessThanOrEqualTo($startsAtUtc)) {
                    abort(422, __('bookings.invalid_time_range'));
                }

                $this->assertSlotAvailable($service, $provider, $startsAtUtc, $endsAtUtc, $tz);

                $unit = (int) round(((float) $service->price) * 100);
                $gross = $unit;
                $fee = (int) round($gross * $feeRate);
                $net = $gross - $fee;

                $item = OrderItem::create([
                    'order_id' => $order->id,
                    'purchasable_type' => Service::class,
                    'purchasable_id' => $service->id,
                    'title_snapshot' => $this->snapshotText($service->title),
                    'description_snapshot' => $this->snapshotText($service->description),
                    'quantity' => 1,
                    'unit_amount' => $unit,
                    'gross_amount' => $gross,
                    'platform_fee_amount' => $fee,
                    'net_amount' => $net,
                    'payee_user_id' => $providerUserId,
                    'status' => 'pending',
                ]);

                ServiceBooking::create([
                    'order_item_id' => $item->id,
                    'service_id' => $service->id,
                    'provider_user_id' => $providerUserId,
                    'customer_user_id' => $order->buyer_id,
                    'status' => 'requested',
                    'starts_at' => $startsAtUtc,
                    'ends_at' => $endsAtUtc,
                    'timezone' => $tz,
                    'location_type' => $data['location_type'] ?? $service->location_type,
                    'location_payload' => $data['location_payload'] ?? null,
                ]);
            }

            $subtotal = (int) OrderItem::where('order_id', $order->id)->sum('gross_amount');
            $order->update([
                'subtotal_amount' => $subtotal,
                'total_amount' => $subtotal,
            ]);

            return response()->json($this->loadOrderRelations($order->fresh()));
        });
    }

    protected function snapshotText(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $locale = app()->getLocale();
        $fallbacks = array_unique([$locale, config('app.fallback_locale'), 'en', 'de', 'ar']);

        foreach ($fallbacks as $key) {
            $candidate = $value[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ($value as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    protected function assertSlotAvailable(
        Service $service,
        ServiceProvider $provider,
        Carbon $startsAtUtc,
        Carbon $endsAtUtc,
        string $tz
    ): void {
        $startLocal = $startsAtUtc->copy()->tz($tz);
        $endLocal = $endsAtUtc->copy()->tz($tz);

        $dow = (int) $startLocal->dayOfWeek;
        $dayHours = $provider->workingHours->where('day_of_week', $dow);

        if ($dayHours->isEmpty()) {
            abort(422, __('bookings.provider_not_available_day'));
        }

        $fits = false;
        foreach ($dayHours as $wh) {
            $whStart = Carbon::parse($startLocal->toDateString() . ' ' . $wh->start_time, $tz);
            $whEnd = Carbon::parse($startLocal->toDateString() . ' ' . $wh->end_time, $tz);

            if ($startLocal->gte($whStart) && $endLocal->lte($whEnd)) {
                $fits = true;
                break;
            }
        }

        if (!$fits) {
            abort(422, __('bookings.outside_working_hours'));
        }

        $timeOffOverlap = $provider->timeOffs
            ->contains(fn($t) => $t->starts_at < $endsAtUtc && $t->ends_at > $startsAtUtc);

        if ($timeOffOverlap) {
            abort(422, __('bookings.provider_time_off'));
        }

        $capacity = (int) ($service->max_concurrent_bookings ?? 1);

        $overlappingCount = ServiceBooking::query()
            ->where('provider_user_id', $provider->user_id)
            ->where('service_id', $service->id)
            ->whereIn('status', ['requested', 'confirmed', 'in_progress'])
            ->where('starts_at', '<', $endsAtUtc)
            ->where('ends_at', '>', $startsAtUtc)
            ->count();

        if ($overlappingCount >= $capacity) {
            abort(422, __('bookings.slot_unavailable'));
        }
    }

    private function loadOrderRelations(Order $order): Order
    {
        return $order->load(['items.serviceBooking', 'items.stripeRefunds', 'stripePayment', 'stripeRefunds']);
    }
}
