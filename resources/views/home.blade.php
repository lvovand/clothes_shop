@extends('layouts.app')

{{--
    Главная в разметке эталона: прелоадер, баннер, бегущая строка, слайдер новинок,
    плитка SHOP. Единственное намеренное отличие — в баннере вместо видео эталона
    показываются слайды из админки (это согласованное отличие бренда).
--}}

@php
    $theme = fn($p) => asset('theme/' . ltrim($p, '/'));
    $setting = fn($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
    $marqueeImage = $setting('home_marquee_image');
    $marqueeText = $setting('home_marquee_text', 'VACATION COLLECTION SS26');
    $bannerLink = $desktopSlides->first()?->link_url ?: $mobileSlides->first()?->link_url;
    $bannerLinkText = $desktopSlides->first()?->link_text ?: $mobileSlides->first()?->link_text;
@endphp

@section('content')

{{-- Заставки-прелоадера у нас нет намеренно: страница показывается сразу
     (на эталоне здесь висел экран с логотипом на две секунды). --}}

<script>
    //marquee
    $(document).ready(function() {
        $('.marquee').marquee({
            duration: 25000,
            startVisible: true,
            duplicated: true,
            direction: 'left'
        });
    });
</script>

<!--main banner-->
<div class="banner-block">
    <div class="container">

        <div class="video-block">
            <div class="banner-block-images swiper banner-block-images-desktop">
                <div class="swiper-wrapper">
                    @foreach($desktopSlides as $slide)
                        <div class="swiper-slide">
                            <img class="before-after__item before-after__item-desktop" src="{{ asset('storage/'.$slide->image) }}" />
                        </div>
                    @endforeach
                </div>
            </div>
            @if($bannerLink)
                <a href="{{ $bannerLink }}" class="banner-block-images__link btn">
                    {{ $bannerLinkText }}
                </a>
            @endif
        </div>

        <div class="banner-block-images swiper banner-block-images-mobile">

            <div class="swiper-wrapper">

                @foreach($mobileSlides as $slide)
                    <div class="swiper-slide">
                        <img class="before-after__item before-after__item-mobile" src="{{ asset('storage/'.$slide->image) }}" />
                    </div>
                @endforeach

            </div>

            @if($bannerLink)
                <a href="{{ $bannerLink }}" class="banner-block-images__link btn">
                    {{ $bannerLinkText }}
                </a>
            @endif

        </div>


    </div>
</div>
<!--main banner END-->

<!--marquee-->
<div class="marquee">
    @for($i = 0; $i < 4; $i++)
        {!! $marqueeText !!}
        @if($marqueeImage)<img src="{{ asset('storage/'.$marqueeImage) }}" alt="Image">@endif
    @endfor
</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const marquee = document.querySelector(".marquee");
        marquee.classList.add("fade-in");
    });
</script>

<!--marquee END-->

<!--new collections-->
<div class="new-collect">
    <div class="container">

        <div class="top-title">
            <p class="h2-title">
                {{ $setting('home_new_title', 'NEW COLLECTIONS') }}
            </p>
            <a href="{{ route('catalog.all') }}" class="link-more">
                {{ $setting('home_new_cta', 'SEE MORE') }}
            </a>
        </div>

        <div class="new-collect-slider swiper">
            <div class="swiper-wrapper">

                @foreach($newProducts as $product)
                    <div class="swiper-slide">
                        @include('partials.product-card', ['product' => $product])
                    </div>
                @endforeach

            </div>

            <div class="slider__prevCol swiper-arrow-effects">
                <img src="{{ $theme('wp-content/themes/ropa-temp/assets/img/icons/svg-srrow.svg') }}"  alt="arrow-left">
            </div>
            <div class="slider__nextCol swiper-arrow-effects">
                <img src="{{ $theme('wp-content/themes/ropa-temp/assets/img/icons/svg-arrowr.svg') }}" alt="arrow-right">
            </div>

        </div>

        <div class="link-more-mobile-block">
            <a href="{{ route('catalog.all') }}" class="link-more link-more_mobile">
                {{ $setting('home_new_cta', 'SEE MORE') }}
            </a>
        </div>


    </div>
</div>
<!--new collections END-->

<!--shop-->
<div class="shop">
    <div class="container">

        <div class="top-title">
            <p class="h2-title">
                {{ $setting('home_shop_title', 'SHOP') }}
            </p>
            <a href="{{ route('catalog.all') }}" class="link-more">
                {{ $setting('home_shop_cta', 'SEE MORE') }}
            </a>
        </div>

        <div class="shop-block">
            @foreach($shopTiles as $tile)
                <a href="{{ $tile['url'] }}" class="shop-block__item">
                    <div class="shop-block-image" style="background: url('{{ $tile['image'] }}') no-repeat center / cover;"></div>
                    <p>
                        {{ $tile['name'] }}
                    </p>
                </a>
            @endforeach
        </div>

        <div class="link-more-mobile-block">
            <a href="{{ route('catalog.all') }}" class="link-more link-more_mobile">
                {{ $setting('home_shop_cta', 'SEE MORE') }}
            </a>
        </div>

    </div>
</div>
<!--shop END-->

@endsection
