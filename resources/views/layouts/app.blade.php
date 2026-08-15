{{--
    Каркас страницы. Стили и скрипты подключаются ровно тем же набором и в том же
    порядке, что на эталонном сайте: каскад зависит от порядка, поэтому менять его
    нельзя (в частности normalize.css там грузится ПОСЛЕ main.css и media.css).
    Все файлы — оригинальные, лежат в public/theme по тем же относительным путям,
    что и на эталоне, чтобы ссылки url() внутри самих CSS разрешались одинаково.
--}}
@php
    /*
        Ссылки на файлы темы и наши ассеты подписываются временем изменения файла.
        Nginx отдаёт статику без Cache-Control, поэтому браузер решает сам, сколько
        её держать: если поправить файл, не меняя адрес, часть посетителей ещё
        какое-то время видела бы старую версию. С ?v=<время файла> адрес меняется
        вместе с содержимым, и такого не бывает.
    */
    $asset = function (string $path) {
        $path = ltrim($path, '/');
        $full = public_path($path);

        return asset($path) . (is_file($full) ? '?v=' . filemtime($full) : '');
    };
    $theme = fn($p) => $asset('theme/' . ltrim($p, '/'));
    $setting = fn($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
    $favicon = $setting('favicon');
@endphp
<!doctype html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Иконки сайта: логотип-стикер бренда. Если в настройках загружен свой
         favicon, он главнее — тогда используется только он. --}}
    @if($favicon)
        <link rel="icon" href="{{ asset('storage/'.$favicon) }}">
        <link rel="apple-touch-icon" href="{{ asset('storage/'.$favicon) }}">
    @else
        {{-- Иконки тоже с версией: .ico браузеры кешируют особенно долго,
             и без смены адреса во вкладке ещё долго висела бы прежняя. --}}
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $asset('img/brand/favicon-32.png') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ $asset('img/brand/favicon-192.png') }}">
        <link rel="apple-touch-icon" sizes="180x180" href="{{ $asset('img/brand/favicon-180.png') }}">
        <link rel="shortcut icon" href="{{ $asset('favicon.ico') }}">
    @endif

    <title>{{ $title ?? $setting('brand_name', 'ROPA WORLD') }}</title>
    @if(!empty($metaDescription))
        <meta name="description" content="{{ $metaDescription }}">
    @endif

    {{-- Превью для мессенджеров и соцсетей. По умолчанию — логотип бренда на
         кремовом фоне (прозрачный PNG там показался бы на чёрном), а страница
         может подставить своё изображение: например, страница товара — его фото. --}}
    @php
        $ogTitle = $ogTitle ?? $title ?? $setting('brand_name', 'ROPA WORLD');
        $ogDescription = $ogDescription ?? ($metaDescription ?? $setting('site_description', ''));
        $ogImageUrl = $ogImage ?? ($setting('og_image') ? asset('storage/'.$setting('og_image')) : $asset('img/brand/og-image.jpg'));
    @endphp
    <meta property="og:type" content="{{ $ogType ?? 'website' }}">
    <meta property="og:site_name" content="{{ $setting('brand_name', 'ROPA WORLD') }}">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:title" content="{{ $ogTitle }}">
    @if($ogDescription)
        <meta property="og:description" content="{{ $ogDescription }}">
    @endif
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    @if($ogDescription)
        <meta name="twitter:description" content="{{ $ogDescription }}">
    @endif
    <meta name="twitter:image" content="{{ $ogImageUrl }}">

    {{-- Перенесено из инлайновых стилей эталона (wp-block-library + global-styles):
         единственное, что оттуда реально видно, — оформление кнопок .wp-element-button
         (кнопки количества в корзине). Стоит до стилей темы, как и на эталоне,
         поэтому её правила по-прежнему перебивают фон и размеры. --}}
    <style>
        .wp-element-button { cursor: pointer }
        :root :where(.wp-element-button, .wp-block-button__link) {
            background-color: #32373c;
            border-width: 0;
            color: #fff;
            font-family: inherit;
            font-size: inherit;
            font-style: inherit;
            font-weight: inherit;
            letter-spacing: inherit;
            line-height: inherit;
            text-decoration: none;
        }
    </style>

    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/load-more-products-for-woocommerce/berocket/assets/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woocommerce/assets/client/blocks/wc-blocks.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/cdek/assets/css/cdek-map.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/smntcs-woocommerce-quantity-buttons/style.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woocommerce/assets/css/brands.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/ajax-search-for-woocommerce-premium/assets/css/style.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/themes/ropa-temp/assets/css/main.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/themes/ropa-temp/assets/libs/fancybox/jquery.fancybox.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('cdn/swiper-bundle.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/themes/ropa-temp/assets/libs/twenty/twentytwenty.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/themes/ropa-temp/assets/css/media.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/themes/ropa-temp/assets/css/normalize.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/back-in-stock-notifier-for-woocommerce/assets/css/frontend.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/back-in-stock-notifier-for-woocommerce/assets/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/back-in-stock-notifier-for-woocommerce/assets/css/intlTelInput.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/load-more-products-for-woocommerce/css/load_products.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/modules/templates/lib/tooltipster/tooltipster.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/modules/woofilters/css/frontend.woofilters.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/css/jquery-ui.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/css/jquery-ui.structure.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/css/jquery-ui.theme.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/modules/woofilters/css/loaders.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/modules/templates/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ $theme('wp-content/plugins/woo-product-filter/modules/woofilters/css/custom.woofilters.css') }}">

    {{-- Наши точечные правки поверх стилей эталона — подключаются последними,
         чтобы перебивать тему. Внутри файла у каждого правила написано, зачем оно. --}}
    <link rel="stylesheet" href="{{ $asset('css/storefront.css') }}">

    {{-- jQuery — в head, как на эталоне: встроенные скрипты страниц обращаются
         к $ до конца документа, а библиотеки темы грузятся уже перед </body>. --}}
    <script src="{{ $theme('wp-content/themes/ropa-temp/assets/libs/jquery/jquery-3.6.0.min.js') }}"></script>

    @stack('styles')

    {{-- Код из админки (Настройки → Код на сайте): счётчики, метрики, пиксели.
         Выводится как есть — это его смысл, поэтому и добавлять его может только
         тот, у кого есть доступ в админку. --}}
    {!! \App\Models\CodeSnippet::render('head') !!}
</head>

<body>

{!! \App\Models\CodeSnippet::render('body_start') !!}

@include('partials.header')

@yield('content')

@include('partials.footer')

<script src="{{ $theme('wp-content/themes/ropa-temp/js/navigation.js') }}"></script>
<script src="{{ $theme('wp-content/themes/ropa-temp/assets/libs/twenty/jquery.twentytwenty.js') }}"></script>
<script src="{{ $theme('wp-content/themes/ropa-temp/assets/libs/fancybox/jquery.fancybox.min.js') }}"></script>
<script src="{{ $theme('cdn/swiper-bundle.min.js') }}"></script>
<script src="{{ $theme('cdn/jquery.marquee.min.js') }}"></script>
<script src="{{ $theme('wp-content/themes/ropa-temp/assets/libs/mask/mask.js') }}"></script>
<script src="{{ $theme('wp-content/themes/ropa-temp/assets/js/main.js') }}"></script>
{{-- Бесконечная подгрузка товаров — как на эталоне: там это плагин
     "Load More Products for WooCommerce", его скрипт и стили уже перенесены,
     поэтому подключаем оригинал с оригинальным же конфигом (никакой своей
     реализации). Скрипт чисто клиентский: идёт по ссылке «следующая страница»
     из nav.woocommerce-pagination и добавляет div.product-item в div.catalog-block.

     Одно осознанное отступление от эталона — "prev_page" и разметка "load_prev".
     У донора prev_page пустой, поэтому кнопка «предыдущие» не показывается никогда,
     и человек, пришедший по прямой ссылке ?page=2, товары первой страницы увидеть
     не может вообще: сама пагинация при infinity_scroll спрятана скриптом. Механика
     в плагине написана и рабочая (ветка replace==2 вставляет товары сверху и
     компенсирует позицию скролла) — включаем её и оформляем кнопку в чёрную кнопку
     темы (.btn-black) вместо донорской фиолетовой «Load Previous». Показывается она
     только там, где есть ссылка a.prev.page-numbers, то есть со второй страницы. --}}
<script>var the_lmp_js_data = {"type": "infinity_scroll", "update_url": "1", "use_mobile": "", "mobile_type": "", "mobile_width": "", "is_AAPF": "", "buffer": "50", "use_prev_btn": "1", "load_image": "<div class=\"lmp_products_loading\"><i class=\"fa fa-spinner lmp_rotate\"></i><span class=\"\"></span></div>", "load_img_class": ".lmp_products_loading", "load_more": "<div class=\"lmp_load_more_button br_lmp_button_settings\"><a class=\"lmp_button \" style=\"font-size: 22px;color: #333333;background-color: #fb9013;padding-top:15px;padding-right:25px;padding-bottom:15px;padding-left:25px;margin-top:px;margin-right:px;margin-bottom:px;margin-left:px; border-top: 0px solid #000; border-bottom: 0px solid #000; border-left: 0px solid #000; border-right: 0px solid #000; border-top-left-radius: 0px; border-top-right-radius: 0px; border-bottom-left-radius: 0px; border-bottom-right-radius: 0px;\" href=\"#load_next_page\">Показать еще</a></div>", "load_prev": "<div class=\"lmp_load_more_button br_lmp_prev_settings\"><a class=\"lmp_button btn btn-black\" href=\"#load_next_page\">Показать предыдущие</a></div>", "lazy_load": "", "lazy_load_m": "", "LLanimation": "", "end_text": "<div class=\"lmp_products_loading\"><span class=\"\"></span></div>", "javascript": {"before_update": "", "after_update": ""}, "products": "div.catalog-block", "item": "div.product-item", "pagination": "nav.woocommerce-pagination", "next_page": "a.next.page-numbers", "prev_page": "a.prev.page-numbers"};</script>
<script src="{{ $theme('wp-content/plugins/load-more-products-for-woocommerce/js/load_products.js') }}"></script>


{{-- Наш общий скрипт — после main.js эталона: он опирается на его разметку
     и местами снимает его обработчики. Версия по времени файла, чтобы
     обновление доезжало до браузера без ручной чистки кеша. --}}
<script src="{{ $asset('js/storefront.js') }}"></script>

@stack('scripts')

{!! \App\Models\CodeSnippet::render('body_end') !!}

</body>
</html>
