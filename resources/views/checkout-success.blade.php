@extends('layouts.app')

{{--
    Страница «спасибо за заказ» в разметке эталона: у него это блок .thx-block из
    шаблона темы (woocommerce/checkout/thankyou.php) — большая клякса, текст и
    подпись бренда, а клик по блоку прячет его (обработчик тоже эталонный).
    Под блоком у эталона показываются детали заказа WooCommerce; у нас там
    короткая сводка и ссылка в каталог.

    Отступление: добавлена строка с номером заказа — у эталона номера нет вообще,
    а покупателю он нужен, чтобы сослаться на заказ в переписке.
--}}

@php
    $brand = \App\Models\SiteSetting::get('brand_name', 'ROPA WORLD');
@endphp

@section('content')

<div class="thx-block">
    <div class="container">

        <div class="thx-block-main">
            <div class="thx-block-item">

                <div class="thx-block-item-top">
                    <svg xmlns="http://www.w3.org/2000/svg" width="147" height="147" viewBox="0 0 147 147" fill="none">
                        <path d="M109.487 35.863C97.5703 25.0536 83.2799 34.4542 77.6242 40.5057C71.0219 22.5512 56.7361 23.2568 50.4184 25.8538C31.4294 34.7121 37.032 53.2779 42.207 61.4536C24.4422 57.7832 17.741 71.0726 16.611 78.1761C19.5779 108.899 48.6521 97.8864 47.25 99.872C45.848 101.858 36.4754 122.433 60.2705 129.374C84.0657 136.315 87.5164 103.33 87.7711 105.668C88.0259 108.006 114.422 116.978 120.136 96.0274C124.708 79.267 111.966 71.139 105.024 69.1701C111.477 62.5717 121.404 46.6724 109.487 35.863Z" stroke="#0C0C0C" stroke-width="3"/>
                        <path d="M104.176 42.2574C93.6364 32.6667 81.217 40.7653 76.3247 46.0134C70.3678 30.2128 57.8669 30.6908 52.3611 32.9049C35.8225 40.4819 40.9102 56.8085 45.5214 64.0247C29.9317 60.6333 24.1951 72.2146 23.2755 78.4292C26.1749 105.384 51.5222 96.0185 50.3142 97.745C49.1061 99.4714 41.1022 117.412 62.0039 123.729C82.9056 130.046 85.6028 101.171 85.8488 103.223C86.0948 105.274 109.294 113.397 114.091 95.0915C117.929 80.4471 106.693 73.1985 100.596 71.4047C106.181 65.685 114.716 51.8481 104.176 42.2574Z" fill="#FB9013"/>
                        <ellipse cx="73.618" cy="73.6172" rx="14.0502" ry="14.8767" transform="rotate(46.292 73.618 73.6172)" fill="#FCCB41"/>
                    </svg>
                    <svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M16.7976 17.7553L4.20264 5.25192L5.2026 4.24463L17.7976 16.748L16.7976 17.7553Z" fill="#0C0C0C"/>
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M4.24463 16.7975L16.748 4.20251L17.7553 5.20248L5.25192 17.7975L4.24463 16.7975Z" fill="#0C0C0C"/>
                    </svg>
                </div>

                <div class="thx-block-item-middle">
                    <p>
                        Спасибо за ваш заказ:)
                    </p>
                    <p class="thx-order-number">
                        Номер заказа: <strong>{{ $order->order_number }}</strong>
                    </p>
                    <p>
                        Вскоре вы получите письмо с трек-номером для отслеживания вашей посылки на указанную почту. Следите за перемещением заказа и ожидаемой датой доставки. Если письмо не придет, свяжитесь с нами.
                    </p>
                </div>

                <div class="thx-block-item-bottom">
                    <p>
                        Спасибо, что выбрали нас
                    </p>
                    <p>
                        {{ $brand }}
                    </p>
                </div>

            </div>
        </div>

    </div>
</div>

<div class="inner-container-main">
    <div class="container">
        <div class="inner-block">
            <div class="inner-block-delivery">
                <h1>заказ {{ $order->order_number }}</h1>
                <div class="ft-block">
                    <p class="ft-block-boldTitle">Сумма заказа: {{ (int) round($order->total) }}&nbsp;&#8381;</p>
                    <p>
                        @if($order->payment_method === 'cod')
                            Оплата при получении. Мы свяжемся с вами для подтверждения заказа.
                        @else
                            Как только оплата подтвердится, мы пришлём письмо с деталями заказа{{ $order->customer_email ? ' на '.$order->customer_email : '' }}.
                        @endif
                    </p>
                </div>
                <a href="{{ route('catalog.all') }}" class="btn add-cart-link">Продолжить покупки</a>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
    {{-- Обработчик эталона: клик по блоку прячет его. --}}
    $(document).ready(function ($) {
        $('body').on('click', '.thx-block', function() {
            $(this).fadeOut(300);
        });
    });
</script>
@endpush
