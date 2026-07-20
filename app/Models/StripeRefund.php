<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeRefund extends Model
{
    protected $fillable = [
        'order_id',
        'order_item_id',
        'service_booking_id',
        'stripe_payment_id',
        'payment_intent_id',
        'charge_id',
        'stripe_refund_id',
        'amount',
        'currency_iso',
        'status',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function serviceBooking()
    {
        return $this->belongsTo(ServiceBooking::class);
    }

    public function stripePayment()
    {
        return $this->belongsTo(StripePayment::class);
    }
}
