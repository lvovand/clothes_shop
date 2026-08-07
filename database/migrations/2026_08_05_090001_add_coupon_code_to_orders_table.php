<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Код промокода фиксируется в заказе: сам купон потом могут
            // отключить или удалить, а в заказе должно остаться, чем считали.
            $table->string('coupon_code')->nullable()->after('discount_total');
            $table->decimal('gift_certificate_used', 10, 2)->default(0)->after('gift_certificate_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['coupon_code', 'gift_certificate_used']);
        });
    }
};
