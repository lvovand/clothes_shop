@extends('layouts.app')

{{--
    Обычная текстовая страница в разметке эталона: крошки, .inner-block и внутри
    контент. У страниц с заголовком (доставка, оферта) эталон оборачивает текст в
    .inner-block-delivery и начинает с h1, у страницы общих условий по
    сертификатам — просто абзацы в .inner-block. Отличает их поле «шаблон»
    (template = text), сам текст лежит в body и правится в админке.

    Разметку внутри body (.ft-block, .ft-block-boldTitle, .ft-twenty-ttu) даёт
    CSS темы — она перенесена вместе с текстом, см. app:import-donor-pages.
--}}

@php
    $withHeading = $page->template === 'text';
@endphp

@section('content')

<div class="inner-container-main">
    <div class="container">

        <div class="breadcrumbs">
            <div class="content-width">
                <div class="wpcourses-breadcrumbs"><a href="{{ url('/') }}">Главная</a><span class="wpcourses-breadcrumbs-sep"> <span class="arrow-bread">/</span> </span><span class="wpcourses-breadcrumbs-last">{{ $page->breadcrumb_title ?: $page->title }}</span></div>
            </div>
        </div>

        <div class="inner-block">
            @if($withHeading)
                <div class="inner-block-delivery">
                    <h1>{{ $page->title }}</h1>
                    @if($page->subtitle)
                        <p class="ft-twenty-ttu">{{ $page->subtitle }}</p>
                    @endif
                    {!! $page->body !!}
                </div>
            @else
                {!! $page->body !!}
            @endif
        </div>

    </div>
</div>

@endsection
