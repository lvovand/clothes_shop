@extends('layouts.app')

{{--
    Карта лояльности в разметке эталона: баннер во всю ширину с надписью
    «LOYALTY CARD» поверх него и кнопкой «создать карту», затем FAQ из спойлеров.
    Инлайновые стили (абсолютная надпись, отрицательный отступ баннера,
    object-fit у картинок) — эталонные, там они тоже прямо в разметке.

    Мобильная картинка лежит в swiper-обёртке .banner-block-images: main.js
    эталона сам инициализирует слайдер по этому классу, даже с одним слайдом.

    Текст FAQ и обе картинки правятся в админке; ссылка регистрации — внешний
    сервис LO.Cards, как и у эталона.
--}}

@php
    // Одна ссылка на обе кнопки (десктопную и мобильную), чтобы они не разъехались.
    $loyaltySignupUrl = \App\Models\SiteSetting::get('loyalty_signup_url', 'https://registration.lo.cards/?hash=GjWl2gIDy4UoMbpr');
@endphp

@section('content')

<main id="primary" class="site-main">
    <article class="page type-page status-publish hentry">
        <div class="entry-content">

            <div style="position: absolute; margin-top: 35%; margin-left: auto; margin-right: auto; z-index: 9999; width: 100%;">
                <p class="footer-block__logo" style="text-align: center; color: white;">{{ $page->title }}</p>
            </div>

            <div class="banner-block" style="margin-top: -10%;">
                <div class="video-block">
                    @if($page->image)
                        <img class="video-desktop" decoding="async" src="{{ asset('storage/'.$page->image) }}" style="object-fit: cover; width: 100%; height: 100%;">
                    @endif
                    <a href="{{ $loyaltySignupUrl }}" class="banner-block-images__link btn">создать карту</a>
                </div>
                <div class="banner-block-images swiper banner-block-images-mobile">
                    <div class="swiper-wrapper">
                        <div class="swiper-slide">
                            @if($page->image_mobile ?: $page->image)
                                <img class="before-after__item before-after__item-mobile" decoding="async" src="{{ asset('storage/'.($page->image_mobile ?: $page->image)) }}" style="object-fit: cover;">
                            @endif
                            <a href="{{ $loyaltySignupUrl }}" class="banner-block-images__link btn">создать карту</a>
                        </div>
                    </div>
                </div>
            </div>

            <p class="footer-block__logo">FAQ</p>

            {!! $page->body !!}

        </div>
    </article>
</main>

@endsection
