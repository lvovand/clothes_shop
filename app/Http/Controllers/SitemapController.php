<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    /**
     * Карта сайта. Собирается из БД и кэшируется на час — планировщика в проекте
     * нет, поэтому обновление «по расписанию» здесь заменяет TTL кэша: первый
     * запрос после его истечения пересоберёт карту с актуальными датами.
     */
    public function __invoke()
    {
        $xml = Cache::remember('sitemap:xml', 3600, fn () => view('sitemap', [
            'urls' => $this->urls(),
        ])->render());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function urls(): array
    {
        $static = [
            ['loc' => route('home'), 'priority' => '1.0', 'changefreq' => 'daily'],
            ['loc' => route('catalog.all'), 'priority' => '0.9', 'changefreq' => 'daily'],
        ];

        $categories = Category::query()
            ->where('is_active', true)
            ->where('is_virtual', false)
            ->notPrivate()
            ->get()
            ->map(fn (Category $category) => [
                'loc' => route('catalog.category', $category),
                'lastmod' => $category->updated_at?->toAtomString(),
                'priority' => '0.8',
                'changefreq' => 'weekly',
            ]);

        $products = Product::listedPublicly()
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->map(fn (Product $product) => [
                'loc' => route('product.show', $product),
                'lastmod' => $product->updated_at?->toAtomString(),
                'priority' => '0.7',
                'changefreq' => 'weekly',
            ]);

        $pages = Page::query()
            ->where('is_active', true)
            ->get(['slug', 'updated_at'])
            ->map(fn (Page $page) => [
                'loc' => url('/'.$page->slug),
                'lastmod' => $page->updated_at?->toAtomString(),
                'priority' => '0.5',
                'changefreq' => 'monthly',
            ]);

        return collect($static)->concat($categories)->concat($products)->concat($pages)->all();
    }
}
