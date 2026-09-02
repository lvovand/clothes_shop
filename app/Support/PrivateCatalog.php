<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;

/**
 * Доступ к закрытым категориям. Введённый промокод запоминается в сессии
 * покупателя — по одному разделу за раз, чтобы код от одной закрытой категории
 * не открывал остальные.
 */
class PrivateCatalog
{
    private const SESSION_KEY = 'unlocked_categories';

    /** @return array<int,int> id разделов, открытых в этой сессии */
    public static function unlockedIds(): array
    {
        return array_map('intval', (array) session(self::SESSION_KEY, []));
    }

    public static function unlock(Category $category): void
    {
        $ids = self::unlockedIds();
        $ids[] = $category->id;
        session([self::SESSION_KEY => array_values(array_unique($ids))]);
    }

    /** Открыт ли раздел покупателю: обычный — всегда, закрытый — после промокода. */
    public static function allows(Category $category): bool
    {
        return ! $category->is_private || in_array($category->id, self::unlockedIds(), true);
    }

    /**
     * Виден ли товар: обычный — всем, лежащий в закрытых категориях — только
     * тому, кто открыл хотя бы одну из них.
     */
    public static function allowsProduct(Product $product): bool
    {
        $private = $product->privateCategories();

        if ($private->isEmpty()) {
            return true;
        }

        return $private->contains(fn (Category $category) => in_array($category->id, self::unlockedIds(), true));
    }
}
