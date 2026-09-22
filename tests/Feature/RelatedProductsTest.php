<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDailyView;
use App\Models\ProductRelated;
use App\Models\User;
use App\Models\Variant;
use App\Services\Catalog\RelatedProducts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Блок «С этим носят»: ручной подбор → популярные из раздела → остальные из раздела.
 */
class RelatedProductsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0 Safari/537.36';

    private Category $tees;

    private Category $pants;

    private Product $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tees = $this->category('tees');
        $this->pants = $this->category('pants');
        $this->main = $this->product('main-tee', $this->tees);
    }

    private function category(string $slug, array $attrs = []): Category
    {
        return Category::create(['slug' => $slug, 'name' => strtoupper($slug), 'is_active' => true, 'is_virtual' => false] + $attrs);
    }

    private function product(string $slug, Category $category, int $stock = 5, string $status = 'published', int $sort = 0): Product
    {
        $product = Product::create([
            'slug' => $slug, 'name' => strtoupper($slug), 'status' => $status,
            'category_id' => $category->id, 'sort_order' => $sort,
        ]);
        $product->categories()->attach($category);
        Variant::create(['product_id' => $product->id, 'sku' => $slug, 'regular_price' => 3000, 'stock_qty' => $stock]);

        return $product;
    }

    private function related(): array
    {
        return app(RelatedProducts::class)->for($this->main->fresh())->pluck('slug')->all();
    }

    public function test_manual_picks_come_first_in_admin_order_from_any_category(): void
    {
        $sameCategory = $this->product('other-tee', $this->tees);
        $a = $this->product('cargo', $this->pants);
        $b = $this->product('jeans', $this->pants);

        ProductRelated::create(['product_id' => $this->main->id, 'related_product_id' => $b->id, 'sort_order' => 1]);
        ProductRelated::create(['product_id' => $this->main->id, 'related_product_id' => $a->id, 'sort_order' => 2]);

        $this->assertSame(['jeans', 'cargo', 'other-tee'], $this->related());
    }

    public function test_link_is_one_way(): void
    {
        $pants = $this->product('cargo', $this->pants);
        ProductRelated::create(['product_id' => $this->main->id, 'related_product_id' => $pants->id, 'sort_order' => 1]);

        $this->assertSame([], app(RelatedProducts::class)->for($pants)->pluck('slug')->all());
    }

    public function test_popular_products_of_the_category_go_before_the_rest(): void
    {
        $this->product('quiet-tee', $this->tees, sort: -10);
        $popular = $this->product('popular-tee', $this->tees);
        $old = $this->product('old-hit-tee', $this->tees);

        ProductDailyView::create(['product_id' => $popular->id, 'date' => now()->subDay()->toDateString(), 'views' => 12]);
        // Просмотры старше окна не учитываются.
        ProductDailyView::create(['product_id' => $old->id, 'date' => now()->subDays(60)->toDateString(), 'views' => 500]);

        $this->assertSame(['popular-tee', 'quiet-tee', 'old-hit-tee'], $this->related());
    }

    public function test_without_stats_falls_back_to_category_order(): void
    {
        $this->product('second', $this->tees, sort: 2);
        $this->product('first', $this->tees, sort: 1);
        $this->product('from-pants', $this->pants);

        $this->assertSame(['first', 'second'], $this->related());
    }

    public function test_hidden_and_sold_out_products_are_not_suggested(): void
    {
        $this->product('draft-tee', $this->tees, status: 'draft');
        $this->product('sold-out-tee', $this->tees, stock: 0);

        $closed = $this->category('closed', ['is_private' => true, 'access_code' => 'X']);
        $secret = $this->product('secret-tee', $this->tees);
        $secret->categories()->attach($closed);

        $draftManual = $this->product('draft-pants', $this->pants, status: 'draft');
        ProductRelated::create(['product_id' => $this->main->id, 'related_product_id' => $draftManual->id, 'sort_order' => 1]);

        $this->assertSame([], $this->related());
    }

    public function test_block_is_limited_and_rendered_on_product_page(): void
    {
        foreach (range(1, 10) as $i) {
            $this->product("tee-$i", $this->tees, sort: $i);
        }

        $this->assertCount(RelatedProducts::LIMIT, $this->related());

        $this->withHeader('User-Agent', self::BROWSER)
            ->get('/product/main-tee')
            ->assertOk()
            ->assertSee('С этим носят')
            ->assertSee('class="related-slider', false)
            ->assertSee('TEE-1');
    }

    public function test_no_block_when_nothing_to_suggest(): void
    {
        $this->withHeader('User-Agent', self::BROWSER)
            ->get('/product/main-tee')
            ->assertOk()
            ->assertDontSee('class="related-slider', false);
    }

    public function test_view_is_counted_once_per_visitor_per_day(): void
    {
        $this->withHeader('User-Agent', self::BROWSER)->get('/product/main-tee')->assertOk();
        $this->withHeader('User-Agent', self::BROWSER)->get('/product/main-tee')->assertOk();

        $this->assertSame(1, (int) ProductDailyView::where('product_id', $this->main->id)->value('views'));
    }

    public function test_bots_and_admins_are_not_counted(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; YandexBot/3.0)')->get('/product/main-tee')->assertOk();
        $this->actingAs(User::factory()->create())
            ->withHeader('User-Agent', self::BROWSER)->get('/product/main-tee')->assertOk();

        $this->assertSame(0, ProductDailyView::count());
    }

    public function test_admin_saves_manual_picks_in_order(): void
    {
        $a = $this->product('cargo', $this->pants);
        $b = $this->product('jeans', $this->pants);

        $this->actingAs(User::factory()->create());

        Livewire::test(EditProduct::class, ['record' => $this->main->getRouteKey()])
            ->assertSee('С этим носят')
            ->set('data.relatedLinks', [
                'x1' => ['related_product_id' => $b->id],
                'x2' => ['related_product_id' => $a->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$b->id, $a->id], $this->main->relatedLinks()->pluck('related_product_id')->all());
        $this->assertSame(['jeans', 'cargo'], array_slice($this->related(), 0, 2));
    }
}
