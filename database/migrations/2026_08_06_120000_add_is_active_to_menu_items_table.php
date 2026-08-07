<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Свой выключатель у пункта меню. До этого скрыть можно было только пункт,
     * связанный с категорией или страницей (через их is_active), а пункт-ссылку —
     * ничем: из-за этого выключенная карта лояльности продолжала висеть в меню.
     */
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
