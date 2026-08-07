<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // 'pickup' | 'courier_moscow' | 'cdek_pvz' | 'cdek_door'
            $table->string('title');
            $table->boolean('is_enabled')->default(true);
            // first-class rule (was a fragile enable_for_methods string-match on the old site):
            // cash-on-delivery is only ever offered where cod_allowed = true.
            $table->boolean('cod_allowed')->default(false);
            $table->decimal('flat_cost', 10, 2)->nullable();
            $table->decimal('free_from_amount', 10, 2)->nullable();
            $table->json('config')->nullable(); // CDEK tariff code, pickup address, etc.
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
