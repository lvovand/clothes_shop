@extends('layouts.app')

{{-- Страница 404 в разметке эталона: заголовок SORRY!, текст, ссылка на главную
     и картинка кота. Инлайновый стиль у секции — тоже эталонный. --}}

@section('content')

<main id="primary" class="site-main">
    <section class="error-404 not-found" style="min-height: calc(75vh - 95px); position: relative;">
        <header class="page-header">
            <div class="container container-404">
                <h1 class="title-h1-404">SORRY!</h1>
                <p class="pr-blue-404">кажется, вы заблудились!
                    Запрошенная страница не найдена:( Пожалуйста, повторите попытку позже или воспользуйтесь поиском для нахождения нужной информации.</p>
                <a href="{{ url('/') }}">ПЕРЕЙТИ НА ГЛАВНУЮ СТРАНИЦУ</a>
                <img src="{{ asset('theme/wp-content/themes/ropa-temp/assets/img/home/cat-404.png') }}" alt="404">
            </div>
        </header>
    </section>
</main>

@endsection
