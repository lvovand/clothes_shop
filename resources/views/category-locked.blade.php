@extends('layouts.app')

{{--
    Закрытый раздел до ввода промокода. Ссылок на такую категорию на сайте нет:
    покупатель попадает сюда только по прямой ссылке из админки, а товары
    увидит после верного кода (CatalogController::unlock).
--}}

@section('content')

<div class="breadcrumbs-catalog">
    <div class="container">
        <div class="breadcrumbs">
            <div class="content-width">
                <nav class="breadcrumbs__wrapper"><a href="{{ url('/') }}">Главная</a>&nbsp;<span class="arrow-bread">/</span>&nbsp;{{ $category->name }}</nav>
            </div>
        </div>
    </div>
</div>

<div class="container">
    <div class="category-lock">
        <h1 class="category-lock__title">{{ $category->name }}</h1>
        <p class="category-lock__text">Раздел закрытый. Введите промокод, чтобы посмотреть товары.</p>

        <form action="{{ route('catalog.unlock', $category) }}" method="post" class="category-lock__form">
            @csrf
            <input type="text"
                   name="access_code"
                   class="category-lock__input"
                   placeholder="Промокод"
                   autocomplete="off"
                   autofocus>
            {{-- Кнопка в стиле эталона: тот же .btn-black, что и «В корзину». --}}
            <button type="submit" class="btn btn-black category-lock__btn">Открыть</button>
        </form>

        @if(session('error'))
            <p class="category-lock__error">{{ session('error') }}</p>
        @endif
    </div>
</div>

@endsection
