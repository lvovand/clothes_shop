@extends('layouts.app')

{{--
    Избранное. У эталона такой страницы нет вообще (в его шапке нет и иконки
    сердца) — поэтому взята его же разметка каталога: .catalog + h1.page-title +
    сетка .catalog-block. Своих классов не добавляем.

    data-wishlist-page: на этой странице снятое сердечко убирает карточку из
    сетки — это делает public/js/storefront.js.
--}}

@section('content')

<div class="content-area" id="primary">
    <main id="main" class="site-main">
        <div class="catalog">
            <div class="container">

                <h1 class="page-title">избранное</h1>

                @if($items->isEmpty())
                    <p class="woocommerce-info">В избранном пока нет товаров.</p>
                @else
                    <div class="catalog-block" data-wishlist-page>
                        @foreach($items as $item)
                            @include('partials.product-card', ['product' => $item->product])
                        @endforeach
                    </div>
                @endif

            </div>
        </div>
    </main>
</div>

@endsection
