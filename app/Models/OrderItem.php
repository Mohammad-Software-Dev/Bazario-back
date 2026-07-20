<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'purchasable_type',
        'purchasable_id',
        'title_snapshot',
        'description_snapshot',
        'quantity',
        'unit_amount',
        'gross_amount',
        'platform_fee_amount',
        'net_amount',
        'payee_user_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function purchasable()
    {
        return $this->morphTo();
    }

    public function payee()
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function serviceBooking()
    {
        return $this->hasOne(ServiceBooking::class);
    }

    public function stripeRefunds()
    {
        return $this->hasMany(StripeRefund::class);
    }
}
