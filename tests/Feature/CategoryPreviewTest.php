<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Текст над товарами и предпросмотр раздела для администраторов.
 */
class CategoryPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'slug' => 'drop', 'name' => 'DROP', 'is_active' => true, 'is_virtual' => false,
            'intro_text' => '<p>Новая коллекция сезона</p>',
        ]);

        $product = Product::create([
            'slug' => 'drop-tee', 'name' => 'DROP TEE', 'status' => 'published',
            'category_id' => $this->category->id, 'sort_order' => 0,
        ]);
        $product->categories()->attach($this->category);

        Variant::create([
            'product_id' => $product->id, 'sku' => 'drop-tee-m',
            'regular_price' => 5000, 'stock_qty' => 5,
        ]);
    }

    public function test_intro_text_is_shown_before_products(): void
    {
        $html = $this->get('/catalog/drop')->assertOk()->getContent();

        $intro = strpos($html, 'Новая коллекция сезона');
        $grid = strpos($html, 'catalog-block');

        $this->assertNotFalse($intro);
        $this->assertLessThan($grid, $intro);
    }

    public function test_no_intro_block_without_text(): void
    {
        $this->category->update(['intro_text' => null]);

        $this->get('/catalog/drop')->assertOk()->assertDontSee('catalog-intro', false);
    }

    public function test_preview_is_hidden_from_guests(): void
    {
        $this->get('/catalog/drop/preview')->assertNotFound();
    }

    public function test_admin_previews_disabled_section(): void
    {
        $this->category->update(['is_active' => false]);
        $this->actingAs(User::factory()->create());

        $this->get('/catalog/drop')->assertNotFound();
        $this->get('/catalog/drop/preview')
            ->assertOk()
            ->assertSee('Новая коллекция сезона')
            ->assertSee('DROP TEE')
            ->assertSee('catalog-preview-bar', false)
            ->assertSee('noindex, nofollow', false);
    }

    public function test_admin_previews_locked_section_without_code(): void
    {
        $this->category->update([
            'is_private' => true, 'access_code' => 'SECRET', 'launch_at' => now()->addDays(3),
        ]);
        $this->actingAs(User::factory()->create());

        $this->get('/catalog/drop/preview')
            ->assertOk()
            ->assertSee('DROP TEE')
            ->assertDontSee('access_code', false);
    }
}
