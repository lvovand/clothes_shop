@extends('layouts.app')

{{--
    Результаты поиска в разметке эталона: у него это шаблон поиска темы —
    #primary > main#main > .catalog > .container с h1.page-title «результаты
    поиска» и той же сеткой карточек .catalog-block, что в каталоге.
--}}

@section('content')

<div class="content-area" id="primary">
    <main id="main" class="site-main">
        <div class="catalog">
            <div class="container">

                <h1 class="page-title">результаты поиска</h1>

                @if($products->isEmpty())
                    <p class="woocommerce-info">
                        @if($query === '')
                            Введите запрос, чтобы найти товары.
                        @else
                            По запросу «{{ $query }}» ничего не найдено.
                        @endif
                    </p>
                @else
                    <div class="catalog-block" id="product-grid">
                        @foreach($products as $product)
                            @include('partials.product-card', ['product' => $product])
                        @endforeach
                    </div>
                @endif

            </div>
        </div>

        @include('partials.pagination', ['paginator' => $products])
    </main>
</div>

@endsection
