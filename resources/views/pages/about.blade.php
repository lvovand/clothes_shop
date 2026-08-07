@extends('layouts.app')

{{--
    «О нас» в разметке эталона: слева подпись и фото, справа заголовок и текст,
    а под ними то же фото ещё раз с классом .image-about-mobile — на узких
    экранах CSS темы скрывает левую картинку и показывает эту.
    Текст страницы (включая строку «EST 2023» со штрихом) лежит в body страницы
    и правится в админке, как и фото с подзаголовком.
--}}

@push('styles')
    {{-- У эталона именно на этой странице свой инлайновый стиль: бежевый фон
         страницы и шапки. Больше ни на одной странице донора его нет. --}}
    <style>
        body, .header {
            background: #EFEDE6;
        }
    </style>
@endpush

@section('content')

<div class="inner-container-main">
    <div class="container">

        <div class="breadcrumbs">
            <div class="content-width">
                <div class="wpcourses-breadcrumbs"><a href="{{ url('/') }}">Главная</a><span class="wpcourses-breadcrumbs-sep"> <span class="arrow-bread">/</span> </span><span class="wpcourses-breadcrumbs-last">{{ $page->breadcrumb_title ?: $page->title }}</span></div>
            </div>
        </div>

        <div class="inner-block">
            <div class="about-block">
                <div class="about-block-left">
                    @if($page->subtitle)
                        <p class="about-block-left__title">{{ $page->subtitle }}</p>
                    @endif
                    @if($page->image)
                        <img src="{{ asset('storage/'.$page->image) }}" alt="about">
                    @endif
                </div>
                <div class="about-block-right">
                    <p class="h2-inner-title">{{ $page->title }}</p>
                    <div class="about-command-right__content">
                        {!! $page->body !!}
                    </div>
                </div>
                @if($page->image)
                    <img src="{{ asset('storage/'.($page->image_mobile ?: $page->image)) }}" alt="about" class="image-about-mobile">
                @endif
            </div>
        </div>

    </div>
</div>

@endsection
