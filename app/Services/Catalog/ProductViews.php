<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Счётчик просмотров карточек для «популярных» в блоке «С этим носят».
 * Посетитель засчитывается один раз в день на товар; роботы и вошедшие
 * в админку не считаются. Хранится только число просмотров за день.
 */
class ProductViews
{
    private const SESSION_KEY = 'viewed_products';

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|scrap|preview|facebookexternalhit|lighthouse|headless|monitor|curl|wget|python|java\/|go-http|okhttp|http-client/i';

    public function record(Product $product, Request $request): void
    {
        if (auth()->check() || $this->isBot((string) $request->userAgent())) {
            return;
        }

        $today = now()->toDateString();
        $seen = $request->session()->get(self::SESSION_KEY, []);
        $ids = ($seen['date'] ?? null) === $today ? ($seen['ids'] ?? []) : [];

        if (in_array($product->id, $ids, true)) {
            return;
        }

        $ids[] = $product->id;
        $request->session()->put(self::SESSION_KEY, ['date' => $today, 'ids' => $ids]);

        // Статистика не должна ронять карточку товара.
        try {
            DB::table('product_daily_views')->upsert(
                [['product_id' => $product->id, 'date' => $today, 'views' => 1]],
                ['product_id', 'date'],
                ['views' => DB::raw('product_daily_views.views + 1')],
            );
        } catch (\Throwable $e) {
            Log::warning('Product view not recorded', ['product' => $product->id, 'error' => $e->getMessage()]);
        }
    }

    private function isBot(string $userAgent): bool
    {
        return $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }
}
