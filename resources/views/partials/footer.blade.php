{{--
    Подвал и модальное меню. Разметка повторяет эталон класс-в-класс, включая классы
    menu-item на пунктах меню (на них завязаны стили) и обёртки контактов.
    Данные — из настроек сайта и меню, которые редактируются в админке.
--}}
@php
    $theme = fn($p) => asset('theme/' . ltrim($p, '/'));
    $setting = fn($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
    $footerMenu = \App\Models\Menu::where('key', 'footer')->with('items.linkable')->first();
    $infoMenu = \App\Models\Menu::where('key', 'informations')->with('items.linkable')->first();
    $primaryMenu = \App\Models\Menu::where('key', 'primary')->with(['items.linkable', 'items.children.linkable'])->first();

    // Отключённый в админке раздел (или страница) не должен показываться в меню.
    $visible = fn ($items) => collect($items ?? [])->filter(fn ($item) => $item->isVisible());

    $phone = $setting('footer_phone');
    $email = $setting('footer_email');
    $address = $setting('footer_address');
    $hours = $setting('footer_hours');
    $mapUrl = $setting('footer_map_url', 'https://yandex.ru/maps/');

    $socials = array_filter([
        ['url' => $setting('social_instagram'), 'icon' => 'icons/i-insta.svg'],
        ['url' => $setting('social_telegram'), 'icon' => 'icons/i-telegram.svg'],
    ], fn($s) => !empty($s['url']));
@endphp

<!--footer-->
<div class="footer">
    <div class="container">

        <div class="footer-block d-flex j-content-sb">

            <a href="/" class="footer-block__logo">
                {{ $setting('brand_name', 'ROPA WORLD') }}            </a>

            <div class="title-footer-block">
                <p>
                    Покупателям
                </p>
                <div class="footer-block-menu">
                    <ul id="footer-menu" class="">@foreach($visible($footerMenu?->items) as $item)<li id="menu-item-{{ $item->id }}" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-{{ $item->id }}"><a href="{{ $item->resolvedUrl() }}">{{ $item->label }}</a></li>
@endforeach</ul>                </div>
            </div>

            <div class="title-footer-block">
                <p>
                    Сервис
                </p>

                <div class="footer-block-menu">
                    <ul id="informations-menu" class="">@foreach($visible($infoMenu?->items) as $item)<li id="menu-item-{{ $item->id }}" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-{{ $item->id }}"><a href="{{ $item->resolvedUrl() }}">{{ $item->label }}</a></li>
@endforeach</ul>                </div>
            </div>

            <div class="title-footer-block">
                <p>
                    Контакты
                </p>
                <div class="footer-block-contacts">
                    <a href="tel:{{ $phone }}" class="footer-block-contacts__phone">
                        {{ $phone }}                    </a>
                    <a href="mailto:{{ $email }}" class="footer-block-contacts__mail">
                        {{ $email }}                    </a>
                    <a href="{{ $mapUrl }}" target="_blank" class="footer-block-contacts__mail">
                        {{ $address }}                    </a>
                    <a href="javascript:void(0);" class="footer-block-contacts__mail">
                        {{ $hours }}                    </a>
                    <div class="footer-block-social">
                        @foreach($socials as $social)
                            <a href="{{ $social['url'] }}" target="_blank">
                                <img src="{{ $theme($social['icon']) }}" alt="">
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>


        </div>

    </div>
</div>
<!--footer END-->

<!--menu-modal-->
<div class="menu-modal" id="menu-modal">

    <div class="menu-modal-block">

        <a href="#" class="header-buttons__open-search">
            <img src="{{ $theme('icons/i-search.svg') }}" alt="">
        </a>

        <ul id="primary-menu" class="">@foreach($visible($primaryMenu?->items) as $item)<li id="menu-item-{{ $item->id }}" class="menu-item menu-item-type-custom menu-item-object-custom{{ $visible($item->children)->isNotEmpty() ? ' menu-item-has-children' : '' }} menu-item-{{ $item->id }}"><a href="{{ $item->resolvedUrl() }}">{{ $item->label }}</a>@if($visible($item->children)->isNotEmpty())
<ul class="sub-menu">@foreach($visible($item->children) as $child)<li id="menu-item-{{ $child->id }}" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-{{ $child->id }}"><a href="{{ $child->resolvedUrl() }}">{{ $child->label }}</a></li>
@endforeach</ul>
@endif</li>
@endforeach</ul>

        <div class="menu-modal-bottom">
            <div class="menu-modal-bottom__contacts">
                <p>
                    CONTACTS
                </p>
                <a href="tel:{{ $phone }}" class="menu-modal-bottom__phone">
                    {{ $phone }}                </a>
                <a href="mailto:{{ $email }}" class="menu-modal-bottom__mail">
                    {{ $email }}                </a>
                <a href="{{ $mapUrl }}" target="_blank" class="menu-modal-bottom__phone">
                    {{ $address }}                </a>
                <a href="javascript:void(0);" class="menu-modal-bottom__mail">
                    {{ $hours }}                </a>
            </div>
            <div class="menu-modal-bottom__with-us">
                <p>
                    WITH US
                </p>
                <div class="menu-modal-social">
                    @foreach($socials as $social)
                        <a href="{{ $social['url'] }}" target="_blank">
                            <img src="{{ $theme($social['icon']) }}" alt="">
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

    </div>

</div>

{{-- Модалка фильтров — как на эталоне, живёт в подвале; наполняется на каталоге. --}}
@if(isset($colorValues) || isset($sizeValues))
    @include('partials.filters-modal')
@endif
