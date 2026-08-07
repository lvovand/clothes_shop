@extends('layouts.app')

{{--
    Lookbook в разметке эталона: вертикальный список коллекций (.tabs-list) и на
    каждую коллекцию пара свайперов — большой слайдер с fancybox и полоса
    превью-миниатюр под ним (thumbs). Поведение целиком берёт на себя main.js
    эталона: переключение вкладок по .tabs-link-element, инициализация Swiper'ов
    для .tab-big-slider/.tab-small-slider, мобильная модалка по .tab-btn-mobile.
    Своего скрипта здесь нет и быть не должно.

    Важно для этого main.js: он ищет .tab-content по :first-child и
    :nth-child(index+1) внутри .tabs-content-right, поэтому там не должно быть
    никаких других детей, а порядок ссылок и вкладок обязан совпадать.
--}}

@section('content')

<div class="inner-container-main">
    <div class="container">

        <div class="breadcrumbs">
            <div class="content-width">
                {{-- Разделитель у эталона именно такой: пробелы по краям и вложенный
                     .arrow-bread — без него крошки короче и ниже на пару пикселей. --}}
                <div class="wpcourses-breadcrumbs"><a href="{{ url('/') }}">Главная</a><span class="wpcourses-breadcrumbs-sep"> <span class="arrow-bread">/</span> </span><span class="wpcourses-breadcrumbs-last">LookBook</span></div>
            </div>
        </div>

        <div class="inner-block inner-block_tabs">
            <div class="tab-btn-mobile">ARCHIVE</div>
            <div class="tabs">
                <div class="max-content tabs-list">
                    <ul>
                        @foreach($collections as $collection)
                            <li>
                                <a href="#tab{{ $loop->iteration }}" class="tabs-link-element @if($loop->first) active @endif">{{ $collection->title }}</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div class="tabs-content-right">
                    @foreach($collections as $collection)
                        <div class="tab-content" id="tab{{ $loop->iteration }}">
                            <!-- big slider -->
                            <div class="max-content sticky-big-slider">
                                <div class="tab-big-slider swiper">
                                    <div class="swiper-wrapper">
                                        @foreach($collection->photos as $photo)
                                            <div class="swiper-slide">
                                                <a href="{{ asset('storage/'.$photo->image) }}" data-fancybox>
                                                    <img src="{{ asset('storage/'.$photo->image) }}" alt="image">
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <!-- big slider END -->
                            <!-- small slider -->
                            <div class="static-small-slider">
                                <div class="tab-small-slider swiper">
                                    <div class="swiper-wrapper">
                                        @foreach($collection->photos as $photo)
                                            <div class="swiper-slide">
                                                <div class="tab-small-img" style="background: url('{{ asset('storage/'.$photo->image) }}') no-repeat center / cover;"></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            <!-- small slider END -->
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>

@endsection
