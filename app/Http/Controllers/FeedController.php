<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Support\ProductFeed;
use Illuminate\Support\Facades\Cache;

class FeedController extends Controller
{
    public function __construct(private readonly ProductFeed $feed)
    {
    }

    /** Яндекс Маркет — формат YML. */
    public function yandexMarket()
    {
        abort_unless($this->enabled('feed_yandex_enabled'), 404);

        return $this->xml('feed:yandex-market', fn () => view('feeds.yandex-market', [
            'shop' => $this->feed->shop(),
            'categories' => $this->feed->categories(),
            'offers' => $this->feed->offers(),
        ])->render());
    }

    /** Google Merchant Center — RSS 2.0 с namespace g:. */
    public function googleMerchant()
    {
        abort_unless($this->enabled('feed_google_enabled'), 404);

        return $this->xml('feed:google-merchant', fn () => view('feeds.google-merchant', [
            'shop' => $this->feed->shop(),
            'offers' => $this->feed->offers(),
        ])->render());
    }

    /**
     * Кэшируем уже отрендеренный XML-текст, а не промежуточные коллекции: строку
     * любой кэш-драйвер хранит без сюрпризов с сериализацией, и отдача фида не
     * упирается в повторный проход по каталогу.
     */
    private function xml(string $key, \Closure $render)
    {
        $body = Cache::remember($key, 3600, $render);

        return response($body, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    private function enabled(string $key): bool
    {
        return SiteSetting::get($key, '1') !== '0';
    }
}
