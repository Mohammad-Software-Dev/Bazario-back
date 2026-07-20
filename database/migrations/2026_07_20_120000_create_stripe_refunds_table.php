<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('service_booking_id')->nullable()->constrained('service_bookings')->nullOnDelete();
            $table->foreignId('stripe_payment_id')->nullable()->constrained('stripe_payments')->nullOnDelete();
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('charge_id')->nullable()->index();
            $table->string('stripe_refund_id')->unique();
            $table->bigInteger('amount');
            $table->string('currency_iso', 3);
            $table->string('status', 64)->nullable();
            $table->string('reason', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['order_item_id', 'service_booking_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_refunds');
    }
};
