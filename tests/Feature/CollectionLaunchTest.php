<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Запуск коллекции по времени: до даты — страница ожидания с отсчётом, после —
 * раздел открывается сам, без крона и правок в админке.
 */
class CollectionLaunchTest extends TestCase
{
    use RefreshDatabase;

    private Category $collection;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = Category::create([
            'slug' => 'all-star', 'name' => 'ALL STAR COLLECTION', 'is_active' => true, 'is_virtual' => false,
            'is_private' => true, 'access_code' => 'DROP2026',
            'launch_at' => now()->addDays(3),
            'teaser_slug' => 'all-star-collection',
            'teaser_title' => 'ALL STAR COLLECTION',
            'teaser_lead' => 'коллекция будет доступна 16 сентября в 12:00',
            'teaser_body' => '<p>Описание коллекции</p>',
        ]);

        $this->product = Product::create([
            'slug' => 'star-tee', 'name' => 'STAR TEE', 'status' => 'published',
            'category_id' => $this->collection->id, 'sort_order' => 0,
        ]);
        $this->product->categories()->attach($this->collection);

        Variant::create([
            'product_id' => $this->product->id, 'sku' => 'star-tee-m',
            'regular_price' => 5000, 'stock_qty' => 5,
        ]);
    }

    public function test_teaser_page_shows_the_countdown_before_launch(): void
    {
        $response = $this->get('/all-star-collection');

        $response->assertOk();
        $response->assertSee('ALL STAR COLLECTION');
        $response->assertSee('коллекция будет доступна 16 сентября в 12:00');
        $response->assertSee('data-countdown', false);
        $response->assertSee($this->collection->launch_at->toIso8601String(), false);
        // Товары до старта не показываем даже здесь.
        $response->assertDontSee('STAR TEE');
    }

    public function test_section_stays_closed_until_launch(): void
    {
        $this->get('/catalog/all-star')
            ->assertOk()
            ->assertSee('Промокод', false)
            ->assertDontSee('STAR TEE');

        // Ссылка для блогеров продолжает работать до старта.
        $this->post('/catalog/all-star/access', ['access_code' => 'DROP2026'])
            ->assertRedirect('/catalog/all-star');
    }

    public function test_section_opens_by_itself_when_the_time_comes(): void
    {
        $this->travelTo(now()->addDays(4));

        $response = $this->get('/catalog/all-star');

        $response->assertOk();
        $response->assertSee('STAR TEE');
        $response->assertDontSee('Промокод', false);
        // Открывшийся раздел индексируется как обычный.
        $response->assertDontSee('noindex', false);
    }

    public function test_teaser_url_leads_to_the_section_after_launch(): void
    {
        $this->travelTo(now()->addDays(4));

        $this->get('/all-star-collection')->assertRedirect(url('/catalog/all-star'));
    }

    public function test_products_join_public_listings_after_launch(): void
    {
        $this->assertFalse(Product::listedPublicly()->where('id', $this->product->id)->exists());
        $this->assertFalse($this->product->isPurchasable());

        $this->travelTo(now()->addDays(4));
        $this->product->refresh();

        $this->assertTrue(Product::listedPublicly()->where('id', $this->product->id)->exists());
        $this->assertTrue($this->product->isPurchasable());
        $this->assertTrue(Category::notPrivate()->where('id', $this->collection->id)->exists());
    }

    public function test_cart_accepts_the_product_only_after_launch(): void
    {
        $variant = $this->product->variants()->first();

        // Корзина отвечает JSON-ом (кнопка на витрине шлёт fetch), отказ — 422.
        $this->postJson('/cart/add', ['variant_id' => $variant->id, 'qty' => 1])->assertStatus(422);

        $this->travelTo(now()->addDays(4));

        $this->postJson('/cart/add', ['variant_id' => $variant->id, 'qty' => 1])->assertOk();
    }

    public function test_menu_item_follows_the_toggle_and_switches_target(): void
    {
        $menu = Menu::firstOrCreate(['key' => 'primary'], ['name' => 'Главное меню']);

        $this->collection->update(['show_in_menu' => true, 'menu_label' => 'ALL STAR COLLECTION']);

        $item = MenuItem::where('menu_id', $menu->id)
            ->where('linkable_type', Category::class)
            ->where('linkable_id', $this->collection->id)
            ->first();

        $this->assertNotNull($item);
        $this->assertSame('ALL STAR COLLECTION', $item->label);
        $this->assertTrue($item->isVisible());
        // До старта пункт ведёт на страницу ожидания.
        $this->assertSame(url('/all-star-collection'), $item->resolvedUrl());

        $this->travelTo(now()->addDays(4));
        $item->refresh()->load('linkable');
        $this->assertSame(url('/catalog/all-star'), $item->resolvedUrl());

        // Снятый тумблер прячет пункт, но не удаляет его вместе с местом в дереве.
        $this->collection->update(['show_in_menu' => false]);
        $this->assertFalse($item->refresh()->isVisible());
    }

    public function test_admin_form_saves_the_launch_settings(): void
    {
        $this->actingAs(User::factory()->create());

        // Время в форме московское, в базе лежит в UTC — 12:00 в Москве это 09:00 UTC.
        Livewire::test(EditCategory::class, ['record' => $this->collection->getRouteKey()])
            ->fillForm([
                'is_private' => true,
                'access_code' => 'DROP2026',
                'launch_at' => '2026-09-16 12:00:00',
                'teaser_slug' => 'all-star-collection',
                'teaser_title' => 'ALL STAR COLLECTION',
                'show_in_menu' => true,
                'menu_label' => 'ALL STAR COLLECTION',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $this->collection->fresh();
        $this->assertSame('2026-09-16 09:00:00', $saved->launch_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('all-star-collection', $saved->teaser_slug);
    }

    public function test_sitemap_cache_expires_by_the_launch_time(): void
    {
        // Ближайший запуск через 3 дня — час TTL короче него, значит не меняется.
        $this->assertSame(3600, Category::cacheTtl(3600));

        $this->collection->update(['launch_at' => now()->addMinutes(10)]);
        $this->assertEqualsWithDelta(600, Category::cacheTtl(3600), 5);
    }
}
