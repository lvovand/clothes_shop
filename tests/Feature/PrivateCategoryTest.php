<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Закрытая категория: доступ по промокоду, товары только для просмотра. */
class PrivateCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Category $private;

    private Product $product;

    private Variant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = Category::create([
            'slug' => 'secret', 'name' => 'SECRET', 'is_active' => true, 'is_virtual' => false,
            'is_private' => true, 'access_code' => 'DROP2026',
        ]);

        $this->product = Product::create([
            'slug' => 'secret-tee', 'name' => 'SECRET TEE', 'status' => 'published',
            'category_id' => $this->private->id, 'sort_order' => 0,
        ]);
        $this->product->categories()->attach($this->private);

        $this->variant = Variant::create([
            'product_id' => $this->product->id, 'sku' => 'secret-tee-m',
            'regular_price' => 4990, 'stock_qty' => 5,
        ]);
    }

    public function test_category_asks_for_the_code_and_hides_products(): void
    {
        $response = $this->get('/catalog/secret');

        $response->assertOk();
        $response->assertSee('Промокод', false);
        $response->assertDontSee('SECRET TEE');
        $response->assertSee('noindex', false);
    }

    public function test_wrong_code_keeps_the_category_closed(): void
    {
        $this->post('/catalog/secret/access', ['access_code' => 'нет'])
            ->assertRedirect();

        $this->get('/catalog/secret')->assertDontSee('SECRET TEE');
    }

    public function test_right_code_opens_the_category_for_the_session(): void
    {
        $this->post('/catalog/secret/access', ['access_code' => 'drop2026'])
            ->assertRedirect(route('catalog.category', $this->private));

        $this->get('/catalog/secret')->assertSee('SECRET TEE');
        $this->get('/product/secret-tee')->assertOk();
    }

    public function test_product_page_is_404_without_the_code(): void
    {
        $this->get('/product/secret-tee')->assertNotFound();
    }

    public function test_product_page_has_no_buy_button_after_unlock(): void
    {
        $this->post('/catalog/secret/access', ['access_code' => 'DROP2026']);

        $response = $this->get('/product/secret-tee');
        $response->assertOk();
        $response->assertSee('только для просмотра', false);
        // Класс кнопки встречается ещё и в скрипте карточки — проверяем саму кнопку.
        $response->assertDontSee('add-to-cart-single button alt', false);
    }

    public function test_cart_refuses_a_private_product(): void
    {
        $this->post('/catalog/secret/access', ['access_code' => 'DROP2026']);

        $this->postJson('/cart/add', ['variant_id' => $this->variant->id, 'qty' => 1])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertEmpty(session('cart', []));
    }

    public function test_private_products_are_hidden_from_catalog_search_and_sitemap(): void
    {
        $open = Category::create(['slug' => 'tshirt', 'name' => 'T-SHIRT', 'is_active' => true, 'is_virtual' => false]);
        $visible = Product::create([
            'slug' => 'open-tee', 'name' => 'OPEN TEE', 'status' => 'published',
            'category_id' => $open->id, 'sort_order' => 0,
        ]);
        $visible->categories()->attach($open);
        Variant::create(['product_id' => $visible->id, 'sku' => 'open-tee-m', 'regular_price' => 1990, 'stock_qty' => 3]);

        // Даже открытый в этой сессии раздел не выносит свои товары в общие списки.
        $this->post('/catalog/secret/access', ['access_code' => 'DROP2026']);

        $catalog = $this->get('/catalog');
        $catalog->assertSee('OPEN TEE');
        $catalog->assertDontSee('SECRET TEE');

        $search = $this->get('/search?q=TEE');
        $search->assertSee('OPEN TEE');
        $search->assertDontSee('SECRET TEE');

        $sitemap = $this->get('/sitemap.xml');
        $sitemap->assertDontSee('/product/secret-tee');
        $sitemap->assertDontSee('/catalog/secret');
        $sitemap->assertSee('/product/open-tee');

        $feed = $this->get('/feeds/yandex-market.yml');
        $feed->assertDontSee('SECRET TEE');
        $feed->assertSee('OPEN TEE');
    }

    public function test_admin_can_mark_a_category_private(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditCategory::class, ['record' => $this->private->getRouteKey()])
            ->fillForm(['is_private' => true, 'access_code' => 'NEWCODE'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('NEWCODE', $this->private->fresh()->access_code);
    }
}
