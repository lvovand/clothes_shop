<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Тумблер «Показывать пункт в левом меню» появился 09.09 со значением «выключено»
 * для всех уже существующих категорий, хотя их пункты в меню уже были. Из-за этого
 * любое сохранение такой категории гасило её пункт (см. CategoryObserver).
 *
 * Здесь приводим данные в соответствие с тем, что реально было в меню: у категорий
 * с пунктом в главном меню тумблер включаем, а пункты, погашенные этим побочным
 * эффектом, возвращаем в меню.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('menus')->where('key', 'primary')->value('id');

        if (! $menuId) {
            return;
        }

        $categoryIds = DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->where('linkable_type', \App\Models\Category::class)
            ->pluck('linkable_id');

        if ($categoryIds->isEmpty()) {
            return;
        }

        DB::table('categories')->whereIn('id', $categoryIds)->update(['show_in_menu' => true]);

        // Вернуть в меню только те пункты, чьи категории включены: выключенная
        // категория не должна всплыть в меню из-за этой миграции.
        $activeCategoryIds = DB::table('categories')
            ->whereIn('id', $categoryIds)
            ->where('is_active', true)
            ->pluck('id');

        DB::table('menu_items')
            ->where('menu_id', $menuId)
            ->where('linkable_type', \App\Models\Category::class)
            ->whereIn('linkable_id', $activeCategoryIds)
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        // Данные, восстановленные по факту содержимого меню, откатывать нечем и незачем.
    }
};
