<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ServiceBooking;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    use ApiResponseTrait;

    public function show(Request $request)
    {
        $user = $request->user()->load([
            'seller.attachments',
            'serviceProvider.attachments',
        ]);

        $result = [
            'user' => $this->serializeUser($user),
        ];

        if ($this->shouldIncludeSummary($request)) {
            $result += $this->buildSummary($user->id, $request);
        }

        return $this->successResponse($result, 'auth', 'fetched_successfully');
    }

    private function buildSummary(int $userId, Request $request): array
    {
        $limit = max(1, min((int) $request->integer('limit', 5), 10));

        $recentOrders = Order::query()
            ->where('buyer_id', $userId)
            ->with(['items', 'items.serviceBooking'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $recentBookings = ServiceBooking::query()
            ->where('customer_user_id', $userId)
            ->with(['service', 'providerUser:id,name,email,phone'])
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get();

        $recentSales = OrderItem::query()
            ->where('payee_user_id', $userId)
            ->whereHas('order', function ($query) {
                $query->where('status', 'paid');
            })
            ->with(['order.buyer:id,name,email,phone', 'serviceBooking'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $recentProviderBookings = ServiceBooking::query()
            ->where('provider_user_id', $userId)
            ->with(['service', 'customerUser:id,name,email,phone'])
            ->orderByDesc('starts_at')
            ->limit($limit)
            ->get();

        return [
            'counts' => [
                'orders' => Order::query()->where('buyer_id', $userId)->count(),
                'bookings' => ServiceBooking::query()->where('customer_user_id', $userId)->count(),
                'sales' => OrderItem::query()
                    ->where('payee_user_id', $userId)
                    ->whereHas('order', function ($query) {
                        $query->where('status', 'paid');
                    })
                    ->count(),
                'provider_bookings' => ServiceBooking::query()
                    ->where('provider_user_id', $userId)
                    ->count(),
            ],
            'recent_orders' => $recentOrders,
            'recent_bookings' => $recentBookings,
            'recent_sales' => $recentSales,
            'recent_provider_bookings' => $recentProviderBookings,
        ];
    }

    private function shouldIncludeSummary(Request $request): bool
    {
        $include = $request->query('include');

        if (is_array($include)) {
            return in_array('summary', $include, true);
        }

        if (is_string($include)) {
            return collect(explode(',', $include))
                ->map(fn(string $value) => trim($value))
                ->contains('summary');
        }

        return false;
    }

    private function serializeUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'age' => $user->age,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $user->getRoleNames()->values(),
            'seller_profile' => $user->seller,
            'service_provider_profile' => $user->serviceProvider,
            'available_upgrades' => [
                'seller' => $user->seller === null,
                'service_provider' => $user->serviceProvider === null,
            ],
        ];
    }
}
