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

@if(!empty($isPreview))
    {{-- Плашка предпросмотра: адрес открывается только под входом в админку. --}}
    <div class="catalog-preview-bar">
        Предпросмотр раздела — эту страницу видят только администраторы.
        <a href="{{ url('/admin/categories/'.$category->slug.'/edit') }}">Вернуться к редактированию</a>
    </div>
@endif

<div class="breadcrumbs-catalog">
    <div class="container">
        <div class="breadcrumbs">
            <div class="content-width">
                <nav class="breadcrumbs__wrapper"><a href="{{ url('/') }}">Главная</a>&nbsp;<span class="arrow-bread">/</span>&nbsp;{{ $category?->name ?? 'ALL' }}</nav>            </div>
        </div>
    </div>
</div>
@if($category?->intro_text)
    {{-- Текст над товарами из карточки раздела. Класс catalog-seo-text даёт
         оформление заголовков, списков и ссылок — то же, что у текста под каталогом. --}}
    <div class="catalog-intro catalog-seo-text">
        <div class="container">
            <div class="content-width">
                {!! $category->intro_text !!}
            </div>
        </div>
    </div>
@endif
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

@if($category?->seo_text)
    {{-- Текст под каталогом нужен поисковикам, но на экране мешает: держим его
         в разметке и уводим за пределы экрана, а над футером показываем кнопку
         «Читать подробнее», которая возвращает блок на место. Без JS блок
         показывается сразу — за это отвечает <noscript> ниже. --}}
    <div class="catalog-seo is-collapsed" data-seo-text>
        <div class="container">
            <div class="content-width">
                <button type="button" class="catalog-seo__toggle" data-seo-toggle>Читать подробнее</button>
            </div>
        </div>
        <div class="catalog-seo-text">
            <div class="container">
                <div class="content-width">
                    {!! $category->seo_text !!}
                </div>
            </div>
        </div>
    </div>
    <noscript>
        <style>
            .catalog-seo.is-collapsed .catalog-seo-text { position: static; width: auto; height: auto; overflow: visible; }
            .catalog-seo__toggle { display: none; }
        </style>
    </noscript>
@endif

@endsection

{{-- Догрузка товаров при прокрутке — работа перенесённого плагина эталона
     (подключён в layout вместе с его конфигом), своего скрипта здесь нет.
     Плагин сам скрывает нумерацию страниц и подставляет её обратно, если
     JS отключён. --}}
