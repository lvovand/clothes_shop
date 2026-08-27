{{--
    Микроразметка Schema.org (JSON-LD). Собирается из данных, которые уже загружены
    для страницы, — вручную ничего размечать не нужно.

    - на всех страницах: Organization + WebSite (с строкой поиска);
    - на странице товара: Product + Offer + BreadcrumbList;
    - на странице категории: BreadcrumbList.
--}}
@php
    $s = fn ($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
    $brand = $s('brand_name', 'ROPA WORLD') ?: 'ROPA WORLD';
    $home = url('/');
    $canonical = $seoCanonical ?? url()->current();

    $crumbs = function (array $items) {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->values()->map(fn ($it, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $it['name'],
                'item' => $it['url'],
            ])->all(),
        ];
    };

    $graph = [];

    $org = [
        '@type' => 'Organization',
        '@id' => $home.'/#organization',
        'name' => $brand,
        'url' => $home,
        'logo' => asset('img/brand/favicon-512.png'),
    ];
    if ($s('footer_phone')) {
        $org['telephone'] = $s('footer_phone');
    }
    if ($s('footer_email')) {
        $org['email'] = $s('footer_email');
    }
    if ($s('footer_address')) {
        $org['address'] = [
            '@type' => 'PostalAddress',
            'streetAddress' => $s('footer_address'),
            'addressCountry' => 'RU',
        ];
    }
    $sameAs = array_values(array_filter([$s('social_instagram'), $s('social_telegram')]));
    if ($sameAs) {
        $org['sameAs'] = $sameAs;
    }
    $graph[] = $org;

    $graph[] = [
        '@type' => 'WebSite',
        '@id' => $home.'/#website',
        'url' => $home,
        'name' => $brand,
        'inLanguage' => 'ru-RU',
        'publisher' => ['@id' => $home.'/#organization'],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => route('search.index').'?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];

    // Проверяем маршрут, а не только наличие $product: на главной и в каталоге
    // переменная $product «протекает» в шаблон из карточек товара в слайдере
    // (Blade рендерит секции до макета), и без этой проверки на них попадала бы
    // разметка Product случайного товара.
    if (request()->routeIs('product.show') && isset($product) && $product instanceof \App\Models\Product) {
        $inStock = $product->variants->contains(fn ($v) => $v->inStock());
        $price = $product->minPrice();
        $skus = $product->variants->pluck('sku')->filter()->unique()->values();

        $offer = [
            '@type' => 'Offer',
            'url' => $canonical,
            'priceCurrency' => 'RUB',
            'availability' => 'https://schema.org/'.($inStock ? 'InStock' : 'OutOfStock'),
            'itemCondition' => 'https://schema.org/NewCondition',
        ];
        if ($price !== null) {
            $offer['price'] = number_format((float) $price, 2, '.', '');
        }

        $node = [
            '@type' => 'Product',
            'name' => $product->name,
            'url' => $canonical,
            'brand' => ['@type' => 'Brand', 'name' => $brand],
            'offers' => $offer,
        ];
        $images = $product->images->map(fn ($i) => asset('storage/'.$i->path))->all();
        if ($images) {
            $node['image'] = $images;
        }
        if ($skus->count() === 1) {
            $node['sku'] = $skus->first();
        }
        $descText = $product->meta_description
            ?: strip_tags((string) optional($product->contentBlocks->first())->body);
        $descText = trim(preg_replace(['/%%[^%]+%%/', '/\s+/'], ['', ' '], $descText));
        if ($descText !== '') {
            $node['description'] = \Illuminate\Support\Str::limit($descText, 480, '');
        }
        if ($product->category) {
            $node['category'] = $product->category->name;
        }
        $graph[] = $node;

        $items = [['name' => 'Главная', 'url' => $home]];
        if ($product->category) {
            $items[] = ['name' => $product->category->name, 'url' => route('catalog.category', $product->category)];
        }
        $items[] = ['name' => $product->name, 'url' => $canonical];
        $graph[] = $crumbs($items);
    } elseif (request()->routeIs('catalog.category') && isset($category) && $category instanceof \App\Models\Category) {
        $graph[] = $crumbs([
            ['name' => 'Главная', 'url' => $home],
            ['name' => $category->name, 'url' => $category->url()],
        ]);
    }

    $jsonLd = json_encode(
        ['@context' => 'https://schema.org', '@graph' => $graph],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
@endphp
<script type="application/ld+json">{!! $jsonLd !!}</script>
