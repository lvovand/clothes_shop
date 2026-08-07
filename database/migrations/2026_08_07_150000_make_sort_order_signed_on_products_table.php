<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Порядок сортировки товаров становится знаковым: чтобы поднять один товар
     * в начало каталога, достаточно поставить ему отрицательное число, не
     * перенумеровывая остальные 35 (у всех сейчас 0).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->change();
        });
    }
};
