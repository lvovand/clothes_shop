<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Складской учёт: остаток перестаёт быть одним числом у варианта и раскладывается
 * по складам. `variants.stock_qty` остаётся как суммарный кеш (по нему работают
 * витрина, фильтры каталога и SOLD OUT) — пересчитывается только через StockService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('city')->nullable();
            // Самовывоз возможен только с того склада, где есть выдача покупателю.
            $table->boolean('allows_pickup')->default(false);
            // Порядок списания при доставке: чем меньше, тем раньше берём.
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('variant_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->integer('qty')->default(0);
            $table->timestamps();
            $table->unique(['variant_id', 'warehouse_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            // Отрицательная — списание, положительная — приход/возврат.
            $table->integer('delta');
            // order, return, adjustment, import
            $table->string('reason', 32);
            // Заказ не удаляем каскадом: движение должно пережить удаление заказа,
            // иначе журнал перестанет объяснять текущий остаток.
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('comment', 500)->nullable();
            $table->timestamps();
            $table->index(['variant_id', 'warehouse_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // Откуда фактически списаны единицы: {"<warehouse_id>": qty}. Позиция
            // может разойтись по двум складам (заказали 3, в Оренбурге было 2).
            $table->json('stock_allocation')->nullable()->after('qty');
        });

        Schema::table('orders', function (Blueprint $table) {
            // Метка идемпотентности: повторная отмена не должна прибавить остаток дважды.
            $table->timestamp('stock_returned_at')->nullable()->after('comment');
        });

        $now = now();

        DB::table('warehouses')->insert([
            [
                'code' => 'moscow', 'name' => 'Москва', 'city' => 'Москва',
                'allows_pickup' => true, 'sort_order' => 10, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'orenburg', 'name' => 'Оренбург', 'city' => 'Оренбург',
                'allows_pickup' => false, 'sort_order' => 0, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        // Весь нынешний остаток числится за Москвой (решение владельца), Оренбург
        // владелец проставит руками — иначе сайт разом ушёл бы в SOLD OUT.
        $moscowId = DB::table('warehouses')->where('code', 'moscow')->value('id');

        DB::table('variants')->orderBy('id')->chunk(200, function ($variants) use ($moscowId, $now) {
            $rows = [];
            foreach ($variants as $variant) {
                $rows[] = [
                    'variant_id' => $variant->id,
                    'warehouse_id' => $moscowId,
                    'qty' => (int) $variant->stock_qty,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) {
                DB::table('variant_stocks')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_returned_at'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('stock_allocation'));
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('variant_stocks');
        Schema::dropIfExists('warehouses');
    }
};
