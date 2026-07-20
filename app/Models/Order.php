<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'buyer_id',
        'status',
        'currency_iso',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'transfer_group',
        'placed_at',
        'paid_at',
        'cancelled_at',
        'metadata',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stripePayment()
    {
        return $this->hasOne(StripePayment::class);
    }

    public function stripeRefunds()
    {
        return $this->hasMany(StripeRefund::class);
    }
}
