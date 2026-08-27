<?php

namespace App\View\Composers;

use App\Models\SiteSetting;
use Illuminate\View\View;

/**
 * Считает финальные <title>, <meta name="description"> и <link rel="canonical">
 * для каждой страницы витрины в одном месте.
 *
 * Контроллеры по-прежнему передают во view свои `title` / `metaDescription`
 * (товар, статическая страница, поиск) — этот композер лишь дополняет их
 * значениями по умолчанию из «Настройки → Сайт» и всегда проставляет canonical,
 * чтобы параметры сортировки/фильтров в каталоге не плодили дубли в индексе.
 */
class SeoComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        $brand = SiteSetting::get('brand_name', 'ROPA WORLD') ?: 'ROPA WORLD';

        $view->with('seoTitle', $this->title($data, $brand));
        $view->with('seoDescription', $this->description($data));
        $view->with('seoCanonical', $this->canonical());
    }

    private function title(array $data, string $brand): string
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' && request()->routeIs('home')) {
            $title = trim((string) SiteSetting::get('seo_home_title', ''));
        }

        if ($title === '') {
            return $brand;
        }

        // Не дописываем бренд, если он уже есть в заголовке (например, его вписали
        // целиком в «SEO → Заголовок главной»).
        return str_contains(mb_strtolower($title), mb_strtolower($brand))
            ? $title
            : $title.' — '.$brand;
    }

    private function description(array $data): ?string
    {
        $desc = trim((string) ($data['metaDescription'] ?? ''));

        if ($desc === '' && request()->routeIs('home')) {
            $desc = trim((string) SiteSetting::get('seo_home_description', ''));
        }

        if ($desc === '' && request()->routeIs('catalog.*')) {
            $desc = trim((string) SiteSetting::get('seo_catalog_description', ''));
        }

        if ($desc === '') {
            $desc = trim((string) SiteSetting::get('seo_default_description', ''));
        }

        return $desc !== '' ? $desc : null;
    }

    private function canonical(): string
    {
        $url = url()->current();
        $page = (int) request()->query('page', 1);

        // Единственный query-параметр, который остаётся в канонической ссылке:
        // вторая страница каталога должна канонизироваться на себя, а не на первую,
        // иначе товары из её «хвоста» выпадают из индекса.
        return $page > 1 ? $url.'?page='.$page : $url;
    }
}
