<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->string('status')->default('new'); // new | awaiting_payment | paid | shipped | completed | cancelled
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->foreignId('shipping_method_id')->nullable()->constrained()->nullOnDelete();
            $table->json('shipping_address')->nullable(); // city/street/house/pvz_code
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->string('payment_method')->nullable(); // 'card' | 'cod'
            $table->string('payment_status')->default('pending'); // pending | paid | failed | refunded
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->string('gift_certificate_code')->nullable();
            $table->decimal('total', 10, 2);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
