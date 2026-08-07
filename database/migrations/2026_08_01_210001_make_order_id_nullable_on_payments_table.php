<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Payment can now also belong to a gift certificate purchase instead of an order —
     * no doctrine/dbal in this project, so drop and recreate the column/FK by hand.
     */
    public function up(): void
    {
        // MODIFY — синтаксис MySQL. На sqlite (локальный стенд для сверки вёрстки)
        // менять тип колонки можно только пересозданием таблицы, а внешние ключи
        // там и так не форсируются — поэтому там шаг пропускается.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        DB::statement('ALTER TABLE payments MODIFY order_id BIGINT UNSIGNED NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });

        DB::statement('ALTER TABLE payments MODIFY order_id BIGINT UNSIGNED NOT NULL');

        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
