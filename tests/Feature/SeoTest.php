<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(array $productAttrs = []): Product
    {
        $category = Category::create(['slug' => 'tshirt', 'name' => 'T-SHIRT', 'is_active' => true, 'is_virtual' => false]);

        $product = Product::create(array_merge([
            'slug' => 'test-tee',
            'name' => 'TEST TEE',
            'status' => 'published',
            'category_id' => $category->id,
            'sort_order' => 0,
        ], $productAttrs));
        $product->categories()->attach($category);

        Variant::create(['product_id' => $product->id, 'sku' => 'test-tee-m', 'regular_price' => 2990, 'stock_qty' => 5]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/test.jpg', 'sort_order' => 0]);

        return $product->fresh();
    }

    public function test_sitemap_is_valid_xml_and_lists_products(): void
    {
        $product = $this->makeProduct();

        $res = $this->get('/sitemap.xml');

        $res->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->assertNotFalse(simplexml_load_string($res->getContent()));
        $res->assertSee(route('product.show', $product), false);
        $res->assertSee(route('catalog.all'), false);
    }

    public function test_robots_txt_file_points_at_sitemap_and_blocks_cart(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Sitemap: https://ropaworld.ru/sitemap.xml', $robots);
        $this->assertStringContainsString('Disallow: /checkout', $robots);
        $this->assertStringContainsString('Disallow: /search', $robots);
    }

    public function test_feeds_are_valid_xml(): void
    {
        $this->makeProduct();

        foreach (['/feeds/yandex-market.yml', '/feeds/google-merchant.xml'] as $url) {
            $res = $this->get($url);
            $res->assertOk();
            $this->assertNotFalse(simplexml_load_string($res->getContent()), "$url is not valid XML");
            $res->assertSee('TEST TEE', false);
        }
    }

    public function test_home_has_canonical_and_org_schema_but_no_product_schema(): void
    {
        $res = $this->get('/');

        $res->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('"@type":"Organization"', false)
            ->assertSee('"@type":"WebSite"', false)
            ->assertDontSee('"@type":"Product"', false);
    }

    public function test_product_page_has_product_schema_and_query_free_canonical(): void
    {
        $product = $this->makeProduct();

        $res = $this->get(route('product.show', $product).'?utm_source=x');

        $res->assertOk()
            ->assertSee('<link rel="canonical" href="'.route('product.show', $product).'">', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('"@type":"Offer"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('<title>TEST TEE — ', false);
    }

    public function test_category_meta_title_and_seo_text_render(): void
    {
        $this->makeProduct();
        $category = Category::firstOrFail();

        $this->get(route('catalog.category', $category))
            ->assertOk()
            ->assertSee('<title>'.e($category->name).' — ', false);

        $category->update(['meta_title' => 'Свой заголовок раздела', 'seo_text' => 'Текст про раздел']);

        $this->get(route('catalog.category', $category))
            ->assertOk()
            ->assertSee('<title>Свой заголовок раздела — ', false)
            ->assertSee('Текст про раздел', false);
    }

    public function test_paginated_catalog_canonical_is_self_referencing(): void
    {
        $this->get('/catalog?page=2')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/catalog').'?page=2">', false);
    }

    public function test_category_admin_form_has_seo_fields(): void
    {
        $this->actingAs(User::factory()->create());
        $category = Category::create(['slug' => 'c', 'name' => 'C', 'is_active' => true, 'is_virtual' => false]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->assertFormFieldExists('meta_title')
            ->assertFormFieldExists('meta_description')
            ->assertFormFieldExists('seo_text')
            ->assertSuccessful();
    }

    public function test_site_settings_page_has_seo_and_feed_fields(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(SiteSettings::class)
            ->assertFormFieldExists('seo_home_title')
            ->assertFormFieldExists('seo_default_description')
            ->assertFormFieldExists('feed_yandex_enabled')
            ->assertSuccessful();
    }
}
