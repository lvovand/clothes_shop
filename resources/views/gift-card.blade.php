@extends('layouts.app')

{{--
    Подарочная карта в разметке эталона: слева «карточка» с брендом, справа
    заголовок, спойлер с условиями (его раскрывает main.js эталона по
    .spoiler-head) и форма покупки.

    Отличие по существу, а не по вёрстке: у эталона форма уходит прямо в виджет
    T-Bank (отдельный третий терминал, никакого учёта на сайте), у нас — свой
    обработчик, который выпускает сертификат с балансом и записывает его в базу.
    Поэтому поля называются по-нашему и добавлен выбор способа оплаты.
--}}

@php
    $setting = fn ($key, $default = '') => \App\Models\SiteSetting::get($key, $default);
    $brand = $setting('brand_name', 'ROPA WORLD');
@endphp

@section('content')

<div class="inner-container-main">
    <div class="container">

        <div class="breadcrumbs">
            <div class="content-width">
                <div class="wpcourses-breadcrumbs"><a href="{{ url('/') }}">Главная</a><span class="wpcourses-breadcrumbs-sep"> <span class="arrow-bread">/</span> </span><span class="wpcourses-breadcrumbs-last">Gift Card</span></div>
            </div>
        </div>

        <div class="inner-block">
            <div class="gift-card">
                <div class="gift-card-left">
                    <p class="gift-card-left__title">{{ $brand }}</p>
                    <p class="gift-card-left__subtitle">online Gift card</p>
                </div>
                <div class="gift-card-right">
                    <div class="gift-card-right-content">
                        <div class="gift-card-right-content__title">
                            <p>{{ $brand }} <span>Online gift card</span></p>
                        </div>
                        <div class="drop-down-item">
                            <div class="spoiler-wrap">
                                <div class="spoiler-head">Условия использования
                                    <div class="drop-arrow">
                                        <img src="{{ asset('theme/wp-content/themes/ropa-temp/assets/img/icons/triangle-down.svg') }}" alt="" decoding="async">
                                    </div>
                                </div>
                                <div class="spoiler-body">
                                    <p><b>{{ $brand }} GIFT CARD ONLINE будет отправлена получателю по электронной почте.</b></p>
                                    <p>Сертификат действителен для покупки товаров на {{ parse_url(url('/'), PHP_URL_HOST) }}</p>
                                    <p>После покупки сертификат не подлежит возврату.</p>
                                    <p>Сертификат не имеет ограничения по сроку действия.</p>
                                    <p>Более подробные условия <a href="{{ url('/all-conditions') }}" target="_blank">тут</a></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if(session('error'))
                        <div class="woocommerce-error-visible">{{ session('error') }}</div>
                    @endif
                    @if($errors->any())
                        <div class="woocommerce-error-visible">
                            @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                        </div>
                    @endif

                    {{-- Внимание на порядок элементов внутри формы: в CSS темы есть
                         правило .form-gift-label:nth-child(4) с отступом 36px вместо 48px.
                         У эталона четвёртый ребёнок формы — одно из скрытых полей T-Bank,
                         то есть правило не срабатывает ни на одном видимом блоке. Поэтому
                         наши скрытые поля стоят ПОСЛЕ блоков с полями: тогда четвёртым
                         ребёнком снова оказывается скрытый input, и отступы совпадают. --}}
                    <form class="form-gift" method="post" action="{{ route('gift-card.purchase') }}">
                        <div class="form-gift-label form-gift-label-sum">
                            <p>Укажите сумму:</p>
                            <label class="amount-label">
                                <input type="text" name="amount" placeholder="от 3000 до 150000" value="{{ old('amount') }}" required>
                            </label>
                        </div>
                        <div class="form-gift-label">
                            <p>Данные получателя:</p>
                            <label><input type="text" name="recipient_name" placeholder="Имя получателя*" value="{{ old('recipient_name') }}" required></label>
                            <label><input type="email" name="recipient_email" placeholder="E-mail получателя*" value="{{ old('recipient_email') }}" required></label>
                            <label><input type="text" name="message" placeholder="Сообщение получателю" value="{{ old('message') }}"></label>
                        </div>
                        <div class="form-gift-label">
                            <p>Ваши данные:</p>
                            <label><input type="text" name="buyer_name" placeholder="Имя*" value="{{ old('buyer_name') }}" required></label>
                            <label><input type="email" name="buyer_email" placeholder="E-mail*" value="{{ old('buyer_email') }}" required></label>
                            <label><input type="tel" name="buyer_phone" placeholder="Телефон*" value="{{ old('buyer_phone') }}" required></label>
                        </div>

                        @csrf
                        @if($paymentMethods->count() === 1)
                            <input type="hidden" name="payment_method" value="{{ $paymentMethods->first()->key }}">
                        @endif

                        @if($paymentMethods->isNotEmpty())
                            @if($paymentMethods->count() > 1)
                                <div class="form-gift-label">
                                    <p>Способ оплаты:</p>
                                    <ul class="col-03">
                                        @foreach($paymentMethods as $method)
                                            <li class="wc_payment_method payment_method_{{ $method->key }}">
                                                <input type="radio" class="input-radio" name="payment_method" id="gift_payment_{{ $method->key }}" value="{{ $method->key }}" {{ $loop->first ? 'checked' : '' }}>
                                                <label for="gift_payment_{{ $method->key }}">{{ $method->name }}</label>
                                                {{-- Как на оформлении заказа: у способов Яндекса свой официальный
                                                     бейдж — платёж по Сплиту и кешбэк считает он сам, сумма
                                                     обновляется скриптом ниже при вводе номинала. --}}
                                                @if(in_array($method->key, ['yandex_pay', 'yandex_split'], true))
                                                    @include('partials.yandex-pay-badge', [
                                                        'amount' => old('amount') ?: 3000,
                                                        'type' => $method->key === 'yandex_split' ? 'bnpl' : 'cashback',
                                                        'size' => 'm',
                                                    ])
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <input type="submit" class="btn btn-black" value="Купить">
                        @else
                            <p class="gray-days">Оплата сертификатов временно недоступна — способы оплаты не настроены.</p>
                        @endif
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection

@push('scripts')
<script>
// Бейдж Сплита показывает платёж от введённого номинала — иначе он остался бы
// на стартовых 3000 ₽ независимо от того, что человек набрал.
document.querySelector('input[name="amount"]')?.addEventListener('input', function () {
    const value = parseFloat(this.value.replace(/[^\d.]/g, ''));
    document.querySelectorAll('yandex-pay-badge').forEach(badge => {
        badge.setAttribute('amount', (value > 0 ? value : 3000).toFixed(2));
    });
});
</script>
@endpush
