{{--
    Шапка. Разметка повторяет эталон класс-в-класс, включая обёртки поисковой формы
    (классы dgwt-wcas-* — на них завязаны стили поискового виджета) и разметку бургера.
    Меняются только данные: название бренда, ссылки и контакты берутся из настроек.
--}}
@php
    $theme = fn($p) => asset('theme/' . ltrim($p, '/'));
    $setting = fn($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
@endphp

<!--search-->
<div class="header__search">
    <div class="header-search-block">
        <div class="dgwt-wcas-search-wrapp dgwt-wcas-has-submit woocommerce dgwt-wcas-style-pirx js-dgwt-wcas-layout-classic dgwt-wcas-layout-classic js-dgwt-wcas-mobile-overlay-enabled">
            <form style="max-width: 434px !important;" class="dgwt-wcas-search-form uk-search uk-search-default d-flex a-item-center" role="search" action="{{ route('search.index') }}" method="get">
                <input id="dgwt-wcas-search-input-1"
                       type="search"
                       class="dgwt-wcas-search-input uk-search-input form-control"
                       name="s"
                       value="{{ request('s', request('q')) }}"
                       placeholder="Поиск товаров..."
                       autocomplete="off"
                />

                <div class="dgwt-wcas-voice-search"></div>

                <button type="submit"
                        aria-label="Поиск"
                        class="dgwt-wcas-search-submit search-btn yellow">                    <svg xmlns="http://www.w3.org/2000/svg" width="31" height="31" viewBox="0 0 31 31" fill="none">
                        <path d="M5.41211 13.4721C5.41211 8.89958 9.11919 5.20541 13.6788 5.20541C18.2384 5.20541 21.9454 8.9125 21.9454 13.4721C21.9454 18.0317 18.2384 21.7387 13.6788 21.7387C9.11919 21.7387 5.41211 18.0317 5.41211 13.4721Z" stroke="#8E8E8E" stroke-width="2" stroke-miterlimit="10"/>
                        <path d="M19.3103 19.53L25.5878 25.7946" stroke="#8E8E8E" stroke-width="2" stroke-miterlimit="10"/>
                    </svg></button>

                <input type="hidden" name="post_type" value="product"/>
                <input type="hidden" name="dgwt_wcas" value="1"/>

            </form>
        </div>
    </div>
    <a href="javascript:void(0);" class="search-close">
        <svg xmlns="http://www.w3.org/2000/svg" width="31" height="31" viewBox="0 0 31 31" fill="none">
            <path fill-rule="evenodd" clip-rule="evenodd" d="M23.6693 25.0189L5.92175 7.40052L7.33079 5.98115L25.0783 23.5995L23.6693 25.0189Z" fill="#0C0C0C"/>
            <path fill-rule="evenodd" clip-rule="evenodd" d="M5.9812 23.6692L23.5995 5.92173L25.0189 7.33077L7.40057 25.0783L5.9812 23.6692Z" fill="#0C0C0C"/>
        </svg>
    </a>
</div>
<!--search END-->

<!--   header-->
<div class="header">
    <div class="container">

        <div class="header-block d-flex a-item-center j-content-sb">
            <a href="#menu-modal" class="menu-burger" data-fancybox="">
                menu
            </a>
            <a href="/" class="header-block__logo">
                {{ $setting('brand_name', 'ROPA WORLD') }}            </a>
            <div class="header-buttons">
                <a href="#" class="header-buttons__open-search">
                    <img src="{{ $theme('icons/i-search.svg') }}" alt="">
                </a>

                <a href="{{ route('wishlist.index') }}" class="header-buttons__use header-buttons__wishlist">
                    <span @if(($wishlistCount ?? 0) > 0) class="active" @endif></span>
                    <img src="{{ $theme('icons/compare.svg') }}" alt="">
                </a>
                <a href="{{ route('checkout.index') }}" class="header-buttons__use header-buttons__use_cart">
                    @if(($cartCount ?? 0) > 0)
                        <span></span>
                    @endif
                    <img src="{{ $theme('icons/cart.svg') }}" alt="">
                </a>
                <a href="#menu-modal" data-fancybox="" class="header-actions__action header-actions__action--menu js-open-nav">
                    <div class="hamburger">
                        <div class="hamburger__wrap">
                            <div class="hamburger__inner"></div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

    </div>
</div>
<!--   header END-->
