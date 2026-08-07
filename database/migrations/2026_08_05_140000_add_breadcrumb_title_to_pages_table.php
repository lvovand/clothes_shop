<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // У эталона в хлебных крошках стоит не тот же текст, что в заголовке
            // страницы: крошка «DELIVERY AND RETURN», а h1 — «доставка и возврат».
            // Пусто — берём заголовок страницы.
            $table->string('breadcrumb_title')->nullable()->after('subtitle');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('breadcrumb_title');
        });
    }
};
