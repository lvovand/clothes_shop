<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;

class CategoryObserver
{
    /**
     * Тумблер «Показывать пункт в левом меню» ведёт настоящий пункт главного меню,
     * а не отдельную ветку в шаблоне: так пункт остаётся перетаскиваемым в разделе
     * «Меню сайта», а адрес берётся из связи (до старта — страница ожидания, после —
     * сам раздел, см. MenuItem::resolvedUrl).
     */
    public function saved(Category $category): void
    {
        $menu = Menu::where('key', 'primary')->first();

        if (! $menu) {
            return;
        }

        $item = MenuItem::where('menu_id', $menu->id)
            ->where('linkable_type', Category::class)
            ->where('linkable_id', $category->id)
            ->first();

        if (! $category->show_in_menu) {
            // Пункт не удаляем: выключенный тумблер можно вернуть, а вместе с ним
            // и прежнее место пункта в дереве меню.
            $item?->update(['is_active' => false]);

            return;
        }

        $label = $category->menu_label ?: $category->name;

        if ($item) {
            $item->update(['label' => $label, 'is_active' => true]);

            return;
        }

        MenuItem::create([
            'menu_id' => $menu->id,
            'parent_id' => null,
            'label' => $label,
            'linkable_type' => Category::class,
            'linkable_id' => $category->id,
            'sort_order' => (int) MenuItem::where('menu_id', $menu->id)->max('sort_order') + 1,
            'is_active' => true,
        ]);
    }
}
