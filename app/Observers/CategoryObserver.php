<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;

class CategoryObserver
{
    /**
     * Переключили ли тумблер в этом сохранении. Считаем до записи (там ещё видно
     * прежнее значение) и запоминаем по объекту: обсервер на каждое событие
     * создаётся заново, поэтому обычное свойство не переживёт saving → saved.
     *
     * @var array<int, bool>
     */
    private static array $menuToggleChanged = [];

    public function saving(Category $category): void
    {
        self::$menuToggleChanged[spl_object_id($category)] = ! $category->exists
            || $category->isDirty('show_in_menu');
    }

    /**
     * Тумблер «Показывать пункт в левом меню» ведёт настоящий пункт главного меню,
     * а не отдельную ветку в шаблоне: так пункт остаётся перетаскиваемым в разделе
     * «Меню сайта», а адрес берётся из связи (до старта — страница ожидания, после —
     * сам раздел, см. MenuItem::resolvedUrl).
     */
    public function saved(Category $category): void
    {
        // Пункт меню трогаем только когда тумблер реально переключили: иначе любая
        // правка категории (текст, фото) гасила бы пункт, который включали руками
        // в разделе «Меню в шапке».
        $toggled = self::$menuToggleChanged[spl_object_id($category)]
            ?? ($category->wasRecentlyCreated || $category->wasChanged('show_in_menu'));

        unset(self::$menuToggleChanged[spl_object_id($category)]);

        $menu = Menu::where('key', 'primary')->first();

        if (! $menu) {
            return;
        }

        $item = MenuItem::where('menu_id', $menu->id)
            ->where('linkable_type', Category::class)
            ->where('linkable_id', $category->id)
            ->first();

        if (! $category->show_in_menu) {
            if ($toggled) {
                // Пункт не удаляем: выключенный тумблер можно вернуть, а вместе с ним
                // и прежнее место пункта в дереве меню.
                $item?->update(['is_active' => false]);
            }

            return;
        }

        $label = $category->menu_label ?: $category->name;

        if ($item) {
            $updates = [];

            if ($toggled) {
                $updates['is_active'] = true;
            }

            // Подпись существующего пункта правится в разделе «Меню в шапке»,
            // поэтому перебиваем её только когда её задали в карточке категории.
            if ($category->wasChanged('menu_label') && filled($category->menu_label)) {
                $updates['label'] = $category->menu_label;
            }

            if ($updates) {
                $item->update($updates);
            }

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
