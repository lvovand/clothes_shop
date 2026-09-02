<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\SiteSetting;
use App\Models\Variant;
use Illuminate\Support\Collection;

/**
 * Единый источник данных для товарных фидов (Яндекс Маркет YML и Google Merchant XML).
 *
 * Оффер = один вариант товара (размер/цвет), потому что цена и наличие у вариантов
 * разные, а площадкам нужен товар в конкретной комплектации.
 */
class ProductFeed
{
    /** @return array{shop_name:string, company:string, url:string, currency:string} */
    public function shop(): array
    {
        $brand = SiteSetting::get('brand_name', 'ROPA WORLD') ?: 'ROPA WORLD';

        return [
            'shop_name' => SiteSetting::get('feed_shop_name') ?: $brand,
            'company' => SiteSetting::get('feed_company') ?: $brand,
            'url' => url('/'),
            'currency' => 'RUB',
        ];
    }

    /** Категории, реально используемые в офферах (id → название). */
    public function categories(): Collection
    {
        return Category::query()
            ->where('is_virtual', false)
            ->notPrivate()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->name]);
    }

    /** @return Collection<int, array> плоский список офферов по вариантам */
    public function offers(): Collection
    {
        return Product::listedPublicly()
            ->with([
                'images',
                'category',
                'categories:id,name',
                'variants.attributeValues.attribute',
            ])
            ->orderBy('id')
            ->get()
            ->flatMap(function (Product $product) {
                $categoryId = $product->category?->id ?? $product->categories->first()?->id;
                $productUrl = route('product.show', $product);
                $images = $product->images->map(fn ($i) => asset('storage/'.$i->path))->values()->all();
                $description = $this->description($product);

                return $product->variants
                    ->filter(fn (Variant $v) => $v->regular_price !== null)
                    ->map(function (Variant $v) use ($product, $categoryId, $productUrl, $images, $description) {
                        $attrs = $v->attributeValues->mapWithKeys(fn ($val) => [
                            $val->attribute?->code => $val->label ?: $val->value,
                        ]);

                        return [
                            'id' => $v->id,
                            'group_id' => $product->id,
                            'available' => $v->inStock(),
                            'name' => $product->name,
                            'url' => $productUrl,
                            'price' => (float) $v->currentPrice(),
                            'old_price' => $v->isOnSale() ? (float) $v->regular_price : null,
                            'currency' => 'RUB',
                            'category_id' => $categoryId,
                            'category_name' => $product->category?->name ?? $product->categories->first()?->name,
                            'pictures' => $images,
                            'description' => $description,
                            'vendor' => SiteSetting::get('brand_name', 'ROPA WORLD') ?: 'ROPA WORLD',
                            'sku' => $v->sku,
                            'size' => $attrs['size'] ?? null,
                            'color' => $attrs['color'] ?? null,
                            'weight' => $product->weight_kg ? (float) $product->weight_kg : null,
                            'dimensions' => $this->dimensions($product),
                        ];
                    });
            })
            ->values();
    }

    private function description(Product $product): string
    {
        $text = $product->meta_description
            ?: strip_tags((string) optional($product->relationLoaded('contentBlocks') ? $product->contentBlocks->first() : $product->contentBlocks()->orderBy('sort_order')->first())->body);

        // %%title%% / %%sitename%% — остатки шаблонов Yoast из старого сайта в
        // импортированных описаниях; в фид их пускать нельзя.
        return trim(preg_replace(['/%%[^%]+%%/', '/\s+/'], ['', ' '], $text));
    }

    private function dimensions(Product $product): ?string
    {
        $l = $product->length_cm;
        $w = $product->width_cm;
        $h = $product->height_cm;

        return ($l && $w && $h) ? "{$l}/{$w}/{$h}" : null;
    }
}
