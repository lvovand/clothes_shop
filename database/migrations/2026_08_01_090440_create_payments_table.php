<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('tbank');
            // unique + nullable: MySQL allows multiple NULLs, but once set this is
            // the idempotency key that stops a duplicate webhook double-processing a payment.
            $table->string('provider_payment_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending | succeeded | failed | refunded
            $table->json('raw_payload')->nullable(); // full webhook body, for audit/replay
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
