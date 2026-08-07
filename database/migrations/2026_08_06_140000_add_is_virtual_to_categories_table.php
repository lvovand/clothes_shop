<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Виртуальная» категория — ALL: в списке категорий она обычная строка (можно
     * переименовать, выключить, переставить), но товары к ней не привязываются,
     * а страница показывает весь каталог по прежнему адресу /catalog.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_virtual')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_virtual');
        });
    }
};
