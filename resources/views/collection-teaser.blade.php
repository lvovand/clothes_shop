@extends('layouts.app')

{{--
    Страница ожидания коллекции. Показывается по своему адресу, пока не наступило
    время запуска; после него CollectionTeaserController уводит отсюда в раздел.

    Каркас страницы — эталонный (крошки + .inner-block, как у текстовых страниц),
    свои только блок баннера и отсчёт: у эталона такой страницы нет, стили лежат
    в public/css/storefront.css с пометкой «страница запуска коллекции».
--}}

@php
    $heading = $category->teaser_title ?: $category->name;
    $banner = $category->teaser_image;
    $bannerMobile = $category->teaser_image_mobile ?: $banner;
@endphp

@section('content')

<div class="inner-container-main">
    <div class="container">

        <div class="breadcrumbs">
            <div class="content-width">
                <div class="wpcourses-breadcrumbs"><a href="{{ url('/') }}">Главная</a><span class="wpcourses-breadcrumbs-sep"> <span class="arrow-bread">/</span> </span><span class="wpcourses-breadcrumbs-last">{{ $heading }}</span></div>
            </div>
        </div>

        <div class="inner-block">
            <div class="collection-teaser">

                @if($banner)
                    <div class="collection-teaser__banner">
                        <img class="collection-teaser__banner-desktop" src="{{ asset('storage/'.$banner) }}" alt="{{ $heading }}" decoding="async">
                        <img class="collection-teaser__banner-mobile" src="{{ asset('storage/'.$bannerMobile) }}" alt="{{ $heading }}" decoding="async">
                    </div>
                @endif

                <h1 class="collection-teaser__title">{{ $heading }}</h1>

                @if($category->teaser_lead)
                    <p class="collection-teaser__lead">{{ $category->teaser_lead }}</p>
                @endif

                {{--
                    Целевое время уходит в ISO 8601 со смещением, поэтому браузер в любом
                    поясе отсчитывает до одного и того же момента (в админке оно вводится
                    по Москве). По нулю страница перезагружается — и уже сервер решает,
                    что показать: контроллер уведёт в открывшийся раздел.
                --}}
                <div class="collection-teaser__timer"
                     data-countdown
                     data-target="{{ $category->launch_at->toIso8601String() }}"
                     data-redirect="{{ $category->url() }}">
                    <div class="collection-teaser__unit">
                        <span class="collection-teaser__num" data-countdown-days>--</span>
                        <span class="collection-teaser__label">дней</span>
                    </div>
                    <div class="collection-teaser__unit">
                        <span class="collection-teaser__num" data-countdown-hours>--</span>
                        <span class="collection-teaser__label">часов</span>
                    </div>
                    <div class="collection-teaser__unit">
                        <span class="collection-teaser__num" data-countdown-minutes>--</span>
                        <span class="collection-teaser__label">минут</span>
                    </div>
                    <div class="collection-teaser__unit">
                        <span class="collection-teaser__num" data-countdown-seconds>--</span>
                        <span class="collection-teaser__label">секунд</span>
                    </div>
                </div>

                @if($category->teaser_body)
                    <div class="collection-teaser__text">
                        {!! $category->teaser_body !!}
                    </div>
                @endif

            </div>
        </div>

    </div>
</div>

@endsection
