@extends('layouts.app')

{{--
    Каталог в разметке эталона: хлебные крошки, строка с кнопкой «Фильтры»,
    сетка .catalog-block из карточек .product-item и нумерованная пагинация.
    Сами фильтры живут в модалке (см. partials/filters-modal), как на эталоне.
--}}

@php
    $theme = fn($p) => asset('theme/' . ltrim($p, '/'));
@endphp

@section('content')

<div class="breadcrumbs-catalog">
    <div class="container">
        <div class="breadcrumbs">
            <div class="content-width">
                <nav class="breadcrumbs__wrapper"><a href="{{ url('/') }}">Главная</a>&nbsp;<span class="arrow-bread">/</span>&nbsp;{{ $title }}</nav>            </div>
        </div>
    </div>
</div>
<!--filters-->
<div class="filters">
    <div class="container">

        <div class="filters-block">
            <a href="#filters-modal" data-fancybox="" class="filters-btn">
                <img src="{{ $theme('wp-content/themes/ropa-temp/assets/img/icons/i-filters.svg') }}" alt="" />
                <span>Фильтры</span>
            </a>
        </div>

    </div>
</div>
<!--filters END-->
<div class="catalog">

    <div class="container">

        <div class="catalog-block" id="product-grid">

            @foreach($products as $product)
                @include('partials.product-card', ['product' => $product])
            @endforeach

        </div>

    </div>

</div>

@include('partials.pagination', ['paginator' => $products])

@endsection

{{-- Догрузка товаров при прокрутке — работа перенесённого плагина эталона
     (подключён в layout вместе с его конфигом), своего скрипта здесь нет.
     Плагин сам скрывает нумерацию страниц и подставляет её обратно, если
     JS отключён. --}}
