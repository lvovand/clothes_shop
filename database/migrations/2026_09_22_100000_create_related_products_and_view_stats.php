<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «С этим носят» — товары, подобранные к товару вручную, в заданном порядке.
        // Связь односторонняя: куртка → штаны не значит штаны → куртка.
        Schema::create('product_related', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('sort_order')->default(0);
            $table->unique(['product_id', 'related_product_id']);
        });

        // Просмотры карточек по дням — одна строка на товар в день, без данных
        // о посетителях. Нужна для «популярных» в блоке «С этим носят».
        Schema::create('product_daily_views', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->primary(['product_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_daily_views');
        Schema::dropIfExists('product_related');
    }
};
