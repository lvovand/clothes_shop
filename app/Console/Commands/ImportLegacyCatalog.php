<?php

namespace App\Console\Commands;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductContentBlock;
use App\Models\ProductImage;
use App\Models\Variant;
use App\Support\LegacyHtml;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Signature('app:import-legacy-catalog {json_path} {--skip-images}')]
#[Description('Import categories/attributes/products from the legacy WordPress export JSON')]
class ImportLegacyCatalog extends Command
{
    // WP term IDs that are not real merchandising categories (confirmed during the site audit):
    // "ALL" is a catch-all pseudo-category matching the whole catalog, "misc"/"hoodie" are empty/unused.
    private const SKIP_CATEGORY_SLUGS = ['all-items', 'misc', 'hoodie'];

    public function handle(): int
    {
        $jsonPath = $this->argument('json_path');

        if (! file_exists($jsonPath)) {
            $this->error("File not found: {$jsonPath}");

            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

        $categoryMap = $this->importCategories($data['categories']);
        $attributeValueMap = $this->importAttributes($data['attribute_values']);

        $productCount = 0;
        $variantCount = 0;
        $imageCount = 0;

        foreach ($this->withProgress($data['products']) as $productData) {
            [$product, $variants, $images] = $this->importProduct($productData, $categoryMap, $attributeValueMap);
            $productCount++;
            $variantCount += $variants;
            $imageCount += $images;
        }

        $this->newLine();
        $this->info("Imported: {$productCount} products, {$variantCount} variants, {$imageCount} images.");

        return self::SUCCESS;
    }

    private function withProgress(array $items): iterable
    {
        $bar = $this->output->createProgressBar(count($items));
        $bar->start();

        foreach ($items as $item) {
            yield $item;
            $bar->advance();
        }

        $bar->finish();
    }

    /** @return array<int,int> old WP term_id => new Category id */
    private function importCategories(array $categories): array
    {
        $map = [];

        foreach ($categories as $cat) {
            if (in_array($cat['slug'], self::SKIP_CATEGORY_SLUGS, true) || $cat['count'] === 0) {
                continue;
            }

            $category = Category::updateOrCreate(
                ['slug' => $cat['slug']],
                ['name' => $cat['name'], 'is_active' => true],
            );

            $map[$cat['id']] = $category->id;
        }

        $this->info(count($map).' categories imported.');

        return $map;
    }

    /** @return array<string,array<string,int>> raw_slug => ['color'|'size' => attribute_value_id] */
    private function importAttributes(array $attributeValues): array
    {
        $map = [];

        foreach (['color' => 'Цвет', 'size' => 'Размер'] as $code => $name) {
            $attribute = Attribute::updateOrCreate(['code' => $code], ['name' => $name]);

            // Multiple raw (legacy) slugs can clean up to the same real value (e.g. the
            // Cyrillic-М "S/М" vs Latin "S/M" duplicate found during the audit) — group
            // by clean_slug so they collapse into a single AttributeValue, but keep every
            // raw_slug mapped to it so variant attribute references still resolve.
            // Values with count=0 are confirmed-unused legacy terms (per the site audit) — drop them.
            $grouped = collect($attributeValues[$code])
                ->filter(fn ($entry) => $entry['count'] > 0)
                ->groupBy('clean_slug');

            foreach ($grouped as $cleanSlug => $entries) {
                $first = $entries->first();

                $attributeValue = AttributeValue::updateOrCreate(
                    ['attribute_id' => $attribute->id, 'value' => $cleanSlug],
                    ['label' => $first['label']],
                );

                foreach ($entries as $entry) {
                    $map[$entry['raw_slug']][$code] = $attributeValue->id;
                }
            }
        }

        return $map;
    }

    /** @return array{0: Product, 1: int, 2: int} [product, variant count, image count] */
    private function importProduct(array $data, array $categoryMap, array $attributeValueMap): array
    {
        $mappedCategoryIds = collect($data['category_ids'])
            ->map(fn ($oldId) => $categoryMap[$oldId] ?? null)
            ->filter()
            ->values();

        $product = Product::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'is_new' => $data['is_new'],
                'status' => 'published',
                'category_id' => $mappedCategoryIds->first(),
                'meta_title' => $data['meta_title'],
                'meta_description' => $data['meta_description'],
                // WooCommerce's weight unit on the source site is grams (confirmed via
                // woocommerce_weight_unit) — our schema stores kilograms.
                'weight_kg' => $data['weight'] ? $data['weight'] / 1000 : null,
                'length_cm' => $data['length'] ?: null,
                'width_cm' => $data['width'] ?: null,
                'height_cm' => $data['height'] ?: null,
            ],
        );

        $product->categories()->sync($mappedCategoryIds);

        // content blocks: replace wholesale on re-import to avoid duplicate rows
        $product->contentBlocks()->delete();
        foreach ($data['content_blocks'] as $i => $block) {
            ProductContentBlock::create([
                'product_id' => $product->id,
                'key' => Str::contains(mb_strtoupper($block['title']), 'УХОД') ? 'description_care' : 'fit',
                'title' => $block['title'],
                // В WP это поле выводилось через wpautop() — абзацы и списки
                // получаются только после него. Экранировать нельзя: в поле
                // лежит разметка, и на странице она должна остаться разметкой.
                'body' => LegacyHtml::autop($block['body']),
                'sort_order' => $i,
            ]);
        }

        $imageIdMap = $this->importImages($product, $data['images']);

        // Recreate variants wholesale on re-import: many legacy variations share an
        // empty SKU, which made updateOrCreate()-by-SKU silently collapse distinct
        // variants into one row the first time this ran — delete+recreate is safe
        // here since no admin edits exist yet on freshly-imported variants.
        $product->variants()->delete();
        $variantCount = 0;
        foreach ($data['variants'] as $variantData) {
            $this->importVariant($product, $variantData, $attributeValueMap, $imageIdMap);
            $variantCount++;
        }

        return [$product, $variantCount, count($imageIdMap)];
    }

    /** @return array<int,int> old WP attachment id => new ProductImage id */
    private function importImages(Product $product, array $images): array
    {
        $map = [];
        $existingByFilename = $product->images()->get()->keyBy(fn (ProductImage $img) => basename($img->path));

        foreach ($images as $i => $img) {
            $filename = basename(parse_url($img['url'], PHP_URL_PATH));
            $storagePath = 'products/'.$filename;

            // Одна и та же картинка приходит дважды, когда обложка товара совпадает
            // с кадром галереи (разные attachment id, один файл) — вторую строку не
            // создаём, иначе в слайдере фото дублируется.
            if ($existing = $existingByFilename->get($filename)) {
                $map[$img['id']] = $existing->id;

                continue;
            }

            if (! $this->option('skip-images') && ! Storage::disk('public')->exists($storagePath)) {
                try {
                    $response = Http::timeout(30)->get($img['url']);
                    if ($response->successful()) {
                        Storage::disk('public')->put($storagePath, $response->body());
                    } else {
                        $this->warn("\nImage download failed ({$response->status()}): {$img['url']}");
                    }
                } catch (\Throwable $e) {
                    $this->warn("\nImage download error: {$img['url']} — {$e->getMessage()}");
                }
            }

            $productImage = ProductImage::create([
                'product_id' => $product->id,
                'path' => $storagePath,
                'sort_order' => $i,
            ]);

            $existingByFilename->put($filename, $productImage);
            $map[$img['id']] = $productImage->id;
        }

        return $map;
    }

    private function importVariant(Product $product, array $data, array $attributeValueMap, array $imageIdMap): void
    {
        $variant = Variant::create([
            'product_id' => $product->id,
            'sku' => $data['sku'] ?: null,
            'regular_price' => $data['regular_price'] ?: 0,
            'sale_price' => $data['sale_price'] ?: null,
            'stock_qty' => $data['stock_qty'],
            'image_id' => $imageIdMap[$data['image_id']] ?? null,
        ]);

        $attributeValueIds = [];
        foreach ($data['attributes'] as $key => $rawSlug) {
            // $key is 'attribute_pa_color' or 'attribute_pa_size'
            $code = Str::after($key, 'attribute_pa_');
            if (isset($attributeValueMap[$rawSlug][$code])) {
                $attributeValueIds[] = $attributeValueMap[$rawSlug][$code];
            }
        }
        $variant->attributeValues()->sync($attributeValueIds);
    }
}
