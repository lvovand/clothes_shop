<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Текст над товарами: необязательный блок из визуального редактора, который
 * выводится на странице раздела перед сеткой товаров (в отличие от seo_text,
 * который стоит под ней и свёрнут до «Читать подробнее»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->text('intro_text')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('intro_text');
        });
    }
};
