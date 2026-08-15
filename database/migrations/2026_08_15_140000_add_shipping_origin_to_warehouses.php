<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Города отгрузки. Раньше точка отправления была одна на весь сайт (настройки
 * «Доставка и оплата»), поэтому доставка всегда считалась из Оренбурга — даже
 * когда товар лежал только в Москве. Теперь точка отправления принадлежит складу:
 * откуда товар физически едет, оттуда и считается доставка, туда же оформляется
 * заявка перевозчику.
 *
 * Заказ, разложенный на два склада, едет двумя отправлениями — у `shipments`
 * появляется склад, чтобы заявки не путались между собой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // СДЭК: код города отправления из его справочника и пункт, куда
            // магазин сдаёт посылки в этом городе.
            $table->unsignedInteger('cdek_sender_city_code')->nullable()->after('allows_pickup');
            $table->string('cdek_shipment_point')->nullable()->after('cdek_sender_city_code');
            // Яндекс: город нужен только чтобы подобрать точки сдачи в админке,
            // в API уходит идентификатор точки.
            $table->string('yandex_dropoff_city')->nullable()->after('cdek_shipment_point');
            $table->string('yandex_dropoff_id')->nullable()->after('yandex_dropoff_city');
        });

        Schema::table('shipments', function (Blueprint $table) {
            // Nullable: у заявок, созданных до появления складов отгрузки, склада нет.
            $table->foreignId('warehouse_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });

        // Прежние общие настройки — это настройки основного склада отгрузки
        // (того, с которого списывали первым). Без переноса сайт после
        // обновления перестал бы считать доставку вообще.
        $primary = DB::table('warehouses')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($primary) {
            $setting = fn (string $key) => DB::table('site_settings')->where('key', $key)->value('value');

            DB::table('warehouses')->where('id', $primary->id)->update([
                'cdek_sender_city_code' => (int) $setting('cdek_sender_city_code') ?: null,
                'cdek_shipment_point' => $setting('cdek_shipment_point') ?: null,
                'yandex_dropoff_city' => $setting('yandex_delivery_dropoff_city') ?: null,
                'yandex_dropoff_id' => $setting('yandex_delivery_dropoff_id') ?: null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn([
                'cdek_sender_city_code',
                'cdek_shipment_point',
                'yandex_dropoff_city',
                'yandex_dropoff_id',
            ]);
        });
    }
};
