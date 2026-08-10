<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Своё превью вместо автоматически уменьшенной копии.
 *
 * Обычно превью каталога — это то же фото, уменьшенное ImageVariants. Но иногда
 * нужна другая картинка (кадр крупнее, другой ракурс) или свой кроп — для этого
 * у фотографии товара и у категории появляется отдельный файл. Пусто = превью
 * делается из основного фото.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};
