<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real internal ledger for gift certificates — the old site's certificates
        // were sold through a bare T-Bank widget with no record kept on the site at all.
        Schema::create('gift_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->decimal('initial_amount', 10, 2);
            $table->decimal('remaining_balance', 10, 2);
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pending'); // pending | active | redeemed | expired
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_certificates');
    }
};
