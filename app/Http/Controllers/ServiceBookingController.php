<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceBooking;
use App\Models\ServiceProvider;
use App\Models\WalletLedgerEntry;
use App\Services\StripeRefundService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceBookingController extends Controller
{
    public function store(Request $request, Service $service)
    {
        $user = $request->user();

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'ends_at' => ['nullable', 'date'],
            'location_type' => ['nullable', 'string', 'max:32'],
            'location_payload' => ['nullable', 'array'],
        ]);

        if (!$service->is_active) {
            return response()->json([
                'message' => __('bookings.service_not_active'),
            ], 422);
        }

        $provider = ServiceProvider::query()
            ->whereKey($service->provider_id)
            ->with(['workingHours', 'timeOffs'])
            ->first();

        if (!$provider) {
            return response()->json([
                'message' => __('bookings.service_provider_not_found'),
            ], 422);
        }

        $providerUserId = $provider->user_id;
        if (!$providerUserId) {
            return response()->json([
                'message' => __('bookings.provider_no_user'),
            ], 422);
        }

        $tz = $data['timezone'] ?? $provider->timezone ?? 'UTC';
        $startsAtUtc = Carbon::parse($data['starts_at'], $tz)->utc();
        if ($startsAtUtc->lessThanOrEqualTo(Carbon::now('UTC'))) {
            return response()->json([
                'message' => __('bookings.start_time_in_past'),
            ], 422);
        }

        if (!empty($data['ends_at'])) {
            $endsAtUtc = Carbon::parse($data['ends_at'], $tz)->utc();
        } else {
            $duration = (int) ($service->duration_minutes ?? 60);
            $endsAtUtc = $startsAtUtc->copy()->addMinutes($duration);
        }

        if ($endsAtUtc->lessThanOrEqualTo($startsAtUtc)) {
            return response()->json([
                'message' => __('bookings.invalid_time_range'),
            ], 422);
        }

        $booking = DB::transaction(function () use ($service, $provider, $providerUserId, $user, $startsAtUtc, $endsAtUtc, $data, $tz) {
            ServiceProvider::whereKey($provider->id)->lockForUpdate()->first();

            $this->assertSlotAvailable($service, $provider, $startsAtUtc, $endsAtUtc, $tz);

            return ServiceBooking::create([
                'order_item_id' => null,
                'service_id' => $service->id,
                'provider_user_id' => $providerUserId,
                'customer_user_id' => $user->id,
                'status' => 'requested',
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
                'timezone' => $tz,
                'location_type' => $data['location_type'] ?? $service->location_type,
                'location_payload' => $data['location_payload'] ?? null,
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);
        });

        return response()->json($booking->load('service'));
    }

    public function myBookings(Request $request)
    {
        $user = $request->user();

        $bookings = ServiceBooking::query()
            ->where('customer_user_id', $user->id)
            ->with(['service', 'providerUser', 'orderItem.order.stripePayment', 'orderItem.stripeRefunds'])
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json($bookings);
    }

    public function providerBookings(Request $request)
    {
        $user = $request->user();

        $bookings = ServiceBooking::query()
            ->where('provider_user_id', $user->id)
            ->with(['service', 'customerUser', 'orderItem.order.stripePayment', 'orderItem.stripeRefunds'])
            ->orderBy('starts_at')
            ->paginate(20);

        return response()->json($bookings);
    }

    public function confirm(Request $request, ServiceBooking $booking)
    {
        $user = $request->user();
        abort_unless($booking->provider_user_id === $user->id, 403);

        if (!in_array($booking->status, ['requested'], true)) {
            return response()->json([
                'message' => __('bookings.confirm_not_allowed'),
            ], 422);
        }

        $booking->status = 'confirmed';
        $booking->save();

        return response()->json($booking);
    }

    public function cancel(Request $request, ServiceBooking $booking, StripeRefundService $refunds)
    {
        $user = $request->user();

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $isProvider = $booking->provider_user_id === $user->id;
        $isCustomer = $booking->customer_user_id === $user->id;
        abort_unless($isProvider || $isCustomer, 403);

        if (in_array($booking->status, ['completed', 'cancelled_by_customer', 'cancelled_by_provider'], true)) {
            return response()->json([
                'message' => __('bookings.already_finalized'),
            ], 422);
        }

        $service = $booking->service()->first();

        if ($isCustomer) {
            $cutoff = (int) ($service?->cancel_cutoff_hours ?? 0);
            $latePolicy = (string) ($service?->cancel_late_policy ?? 'deny');
            if (
                $cutoff > 0
                && $latePolicy === 'deny'
                && $this->isAfterCutoff($booking->starts_at, $cutoff)
            ) {
                return response()->json([
                    'message' => __('bookings.cancel_not_allowed_time'),
                ], 422);
            }
        }

        $refund = DB::transaction(function () use ($booking, $data, $isProvider, $refunds) {
            $booking->status = $isProvider ? 'cancelled_by_provider' : 'cancelled_by_customer';
            $booking->cancelled_at = now();
            $booking->cancellation_reason = $data['reason'] ?? null;
            $booking->save();

            $booking->loadMissing(['orderItem.order.stripePayment', 'orderItem.stripeRefunds']);
            $order = $booking->orderItem?->order;

            if ($order && in_array($order->status, ['paid', 'partially_refunded'], true)) {
                return $refunds->refundBookingCancellation(
                    $booking,
                    $isProvider ? 'provider_cancellation' : 'customer_cancellation'
                );
            }

            return null;
        });

        $freshBooking = $booking->fresh(['service', 'orderItem.order', 'orderItem.stripeRefunds']);
        $freshOrder = $freshBooking?->orderItem?->order?->fresh(['stripePayment', 'stripeRefunds']);

        return response()->json([
            'booking' => $freshBooking,
            'refund' => [
                'applied' => (bool) $refund,
                'amount' => $refund?->amount,
                'status' => $refund?->status,
                'stripe_refund_id' => $refund?->stripe_refund_id,
            ],
            'order_status' => $freshOrder?->status,
        ]);
    }

    public function complete(Request $request, ServiceBooking $booking)
    {
        $user = $request->user();
        abort_unless($booking->provider_user_id === $user->id, 403);

        if (!in_array($booking->status, ['confirmed', 'in_progress'], true)) {
            return response()->json([
                'message' => __('bookings.complete_not_allowed'),
            ], 422);
        }

        DB::transaction(function () use ($booking) {
            $booking->status = 'completed';
            $booking->save();

            if ($booking->order_item_id) {
                WalletLedgerEntry::query()
                    ->where('order_item_id', $booking->order_item_id)
                    ->where('type', 'sale_pending')
                    ->update([
                        'available_on' => now(),
                    ]);
            }
        });

        return response()->json($booking->fresh());
    }

    public function reschedule(Request $request, ServiceBooking $booking)
    {
        $user = $request->user();

        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $isProvider = $booking->provider_user_id === $user->id;
        $isCustomer = $booking->customer_user_id === $user->id;
        abort_unless($isProvider || $isCustomer, 403);

        if (in_array($booking->status, ['completed', 'cancelled_by_customer', 'cancelled_by_provider'], true)) {
            return response()->json([
                'message' => __('bookings.already_finalized'),
            ], 422);
        }

        $service = $booking->service()->first();
        if (!$service || !$service->is_active) {
            return response()->json([
                'message' => __('bookings.service_not_active'),
            ], 422);
        }

        $provider = ServiceProvider::query()
            ->whereKey($service->provider_id)
            ->with(['workingHours', 'timeOffs'])
            ->first();

        if (!$provider) {
            return response()->json([
                'message' => __('bookings.service_provider_not_found'),
            ], 422);
        }

        $tz = $data['timezone'] ?? $provider->timezone ?? 'UTC';
        $startsAtUtc = Carbon::parse($data['starts_at'], $tz)->utc();
        if ($startsAtUtc->lessThanOrEqualTo(Carbon::now('UTC'))) {
            return response()->json([
                'message' => __('bookings.start_time_in_past'),
            ], 422);
        }

        if (!empty($data['ends_at'])) {
            $endsAtUtc = Carbon::parse($data['ends_at'], $tz)->utc();
        } else {
            $duration = (int) ($service->duration_minutes ?? 60);
            $endsAtUtc = $startsAtUtc->copy()->addMinutes($duration);
        }

        if ($endsAtUtc->lessThanOrEqualTo($startsAtUtc)) {
            return response()->json([
                'message' => __('bookings.invalid_time_range'),
            ], 422);
        }

        if ($isCustomer) {
            $cutoff = (int) ($service->edit_cutoff_hours ?? 0);
            $latePolicy = (string) ($service->edit_late_policy ?? 'deny');
            if (
                $cutoff > 0
                && $latePolicy === 'deny'
                && $this->isAfterCutoff($booking->starts_at, $cutoff)
            ) {
                return response()->json([
                    'message' => __('bookings.edit_not_allowed_time'),
                ], 422);
            }
        }

        $updated = DB::transaction(function () use ($service, $provider, $booking, $startsAtUtc, $endsAtUtc, $tz) {
            ServiceProvider::whereKey($provider->id)->lockForUpdate()->first();

            $this->assertSlotAvailable(
                $service,
                $provider,
                $startsAtUtc,
                $endsAtUtc,
                $tz,
                $booking->id
            );

            $booking->starts_at = $startsAtUtc;
            $booking->ends_at = $endsAtUtc;
            $booking->timezone = $tz;
            $booking->status = 'requested';
            $booking->save();

            return $booking;
        });

        return response()->json($updated->load('service'));
    }

    protected function isAfterCutoff($startsAt, int $cutoffHours): bool
    {
        if (!$startsAt || $cutoffHours <= 0) return false;
        $now = Carbon::now('UTC');
        $startUtc = $startsAt instanceof Carbon ? $startsAt : Carbon::parse($startsAt, 'UTC');
        return $now->copy()->addHours($cutoffHours)->gt($startUtc);
    }

    protected function assertSlotAvailable(
        Service $service,
        ServiceProvider $provider,
        Carbon $startsAtUtc,
        Carbon $endsAtUtc,
        string $tz,
        ?int $ignoreBookingId = null
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

        $overlapQuery = ServiceBooking::query()
            ->where('provider_user_id', $provider->user_id)
            ->where('service_id', $service->id)
            ->whereIn('status', ['requested', 'confirmed', 'in_progress'])
            ->where('starts_at', '<', $endsAtUtc)
            ->where('ends_at', '>', $startsAtUtc);

        if ($ignoreBookingId) {
            $overlapQuery->where('id', '!=', $ignoreBookingId);
        }

        $overlappingCount = $overlapQuery->count();

        if ($overlappingCount >= $capacity) {
            abort(422, __('bookings.slot_unavailable'));
        }
    }
}
