<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Support\PrivateCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Блок «С этим носят» на карточке товара.
 *
 * Сначала товары, подобранные вручную в админке (из любых разделов, в заданном
 * порядке). Если их меньше, чем помещается в блок, добираем товарами из тех же
 * разделов: сначала самые просматриваемые за последние дни, а пока статистики
 * нет — в порядке каталога.
 */
class RelatedProducts
{
    public const LIMIT = 8;

    /** За сколько дней считаются просмотры при выборе популярных. */
    public const POPULAR_DAYS = 30;

    /** @return Collection<int,Product> */
    public function for(Product $product, int $limit = self::LIMIT): Collection
    {
        $manualIds = $product->relatedLinks()->pluck('related_product_id')->all();

        $manual = $this->visible()
            ->whereKey($manualIds)
            ->whereKeyNot($product->id)
            ->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $manualIds, true))
            ->values();

        $need = $limit - $manual->count();
        if ($need <= 0) {
            return $manual->take($limit);
        }

        $categoryIds = $product->categories()->pluck('categories.id')
            ->push($product->category_id)
            ->filter()
            ->unique()
            ->all();

        if ($categoryIds === []) {
            return $manual;
        }

        $auto = $this->visible()
            ->whereKeyNot($product->id)
            ->whereNotIn('products.id', $manualIds)
            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $categoryIds))
            // Советовать то, что нельзя купить, незачем; ручной подбор — на усмотрение админа.
            ->whereHas('variants', fn ($q) => $q->where('stock_qty', '>', 0))
            ->withSum(['dailyViews as recent_views' => fn ($q) => $q->where('date', '>=', now()->subDays(self::POPULAR_DAYS)->toDateString())], 'views')
            // Без просмотров сумма — NULL, при сортировке по убыванию такие уходят в конец.
            ->orderByDesc('recent_views')
            ->orderBy('sort_order')
            ->orderByDesc('products.id')
            ->limit($need)
            ->get();

        return $manual->concat($auto)->values();
    }

    /**
     * Товары, которые этот посетитель может видеть: опубликованные и не лежащие
     * в закрытых разделах (кроме тех, что он сам открыл промокодом).
     */
    private function visible(): Builder
    {
        $unlocked = PrivateCatalog::unlockedIds();

        return Product::published()
            ->whereDoesntHave('categories', fn ($q) => $q->lockedNow()->whereNotIn('categories.id', $unlocked))
            ->with(['images', 'variants']);
    }
}
