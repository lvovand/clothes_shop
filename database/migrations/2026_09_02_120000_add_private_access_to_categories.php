<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Закрытая категория: на сайт не выводится, открывается только по прямой
     * ссылке после ввода промокода, товары из неё нельзя купить.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('is_active');
            $table->string('access_code')->nullable()->after('is_private');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['is_private', 'access_code']);
        });
    }
};
