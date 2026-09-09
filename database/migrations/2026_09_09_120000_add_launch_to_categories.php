<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Запуск коллекции по времени: закрытый раздел до даты старта показывает
 * страницу ожидания с обратным отсчётом, после неё сам становится обычным.
 * Время хранится в UTC (в админке вводится по Москве), пункт меню ведётся
 * из карточки категории.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->timestamp('launch_at')->nullable()->after('access_code');
            $table->string('teaser_slug')->nullable()->unique()->after('launch_at');
            $table->string('teaser_title')->nullable()->after('teaser_slug');
            $table->text('teaser_lead')->nullable()->after('teaser_title');
            $table->text('teaser_body')->nullable()->after('teaser_lead');
            $table->string('teaser_image')->nullable()->after('teaser_body');
            $table->string('teaser_image_mobile')->nullable()->after('teaser_image');
            $table->boolean('show_in_menu')->default(false)->after('teaser_image_mobile');
            $table->string('menu_label')->nullable()->after('show_in_menu');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['teaser_slug']);
            $table->dropColumn([
                'launch_at', 'teaser_slug', 'teaser_title', 'teaser_lead', 'teaser_body',
                'teaser_image', 'teaser_image_mobile', 'show_in_menu', 'menu_label',
            ]);
        });
    }
};
