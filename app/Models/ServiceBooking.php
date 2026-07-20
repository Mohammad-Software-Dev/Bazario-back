<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceBooking extends Model
{
    protected $fillable = [
        'order_item_id',
        'service_id',
        'provider_user_id',
        'customer_user_id',
        'status',
        'starts_at',
        'ends_at',
        'timezone',
        'location_type',
        'location_payload',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'location_payload' => 'array',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    public function customerUser()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function stripeRefunds()
    {
        return $this->hasMany(StripeRefund::class);
    }
}
