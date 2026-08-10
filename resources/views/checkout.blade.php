@extends('layouts.app')

{{--
    Оформление заказа в разметке эталона. У эталона корзина и оформление — одна
    страница /checkout/, и его main.js после загрузки перекладывает блоки:
    innerHTML скрытой .cart-main переносится в начало .ordering-checkout-left,
    а список способов оплаты — из .wc_payment_methods в ul.col-03. Здесь сразу
    отрисован КОНЕЧНЫЙ вид этого DOM, поэтому дублирующих блоков-источников нет:
    $('.cart-main') у нас не находится, а prepend(undefined) в jQuery ничего не
    делает — перестановка эталонного скрипта просто проходит впустую.

    Осознанные отступления от эталонного HTML:
      * нет скрытых полей города/улицы/дома/квартиры в блоке контактных данных
        (эталон их выводит и тут же скрывает через display:none по id) — адрес
        вводится в тех же «фейковых» полях .block-fake-adress, что и у эталона;
      * нет служебных обёрток .woocommerce / .checkout-inner-block / .row и
        блока купона .form-coupon-original — по ним нет ни одного правила CSS,
        а поле промокода живёт в сводке справа, как у эталона;
      * нет картинки платёжного логотипа (.wc_payment_method img эталон скрывает сам);
      * состав онлайн-способов свой: у эталона это «Долями», у нас — то, что
        включено в админке (Т-Банк, Яндекс Пэй, Яндекс Сплит);
      * у способа доставки CDEK: пункт выдачи выбирается на карте — блок с
        кнопкой карты аналога у эталона не имеет.
--}}

@php
    // Эталон считает в «товаров: N» именно штуки, а не строки корзины.
    $count = (int) $items->sum('qty');
    // Формат чисел как у эталона: без разделителя тысяч и без копеек.
    $price = fn ($value) => (int) round((float) $value);
    $setting = fn ($key, $default = '') => \App\Models\SiteSetting::get($key, $default);

    // Способы доставки, которым нужен адрес: у эталона поля улица/дом/квартира
    // переносятся скриптом внутрь выбранного пункта, здесь так же (см. JS ниже).
    // Список собирается из самих способов (kind = door), а не перечислением кодов —
    // иначе каждый новый способ пришлось бы дописывать сюда руками.
    $needsAddress = $shippingMethods->filter->needsAddress()->pluck('code')->all();
@endphp

@section('content')

<div class="inner-container-main">
    <div class="container">
        <div class="inner-container-main-checkout">
            <a class="product-card__back sticky" href="javascript:void(0);" onclick="history.back();">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="28" viewBox="0 0 15 28" fill="none"><path fill-rule="evenodd" clip-rule="evenodd" d="M14.3882 0.538115C15.1702 1.2875 15.2075 2.54046 14.4715 3.33669L4.61465 14L14.4715 24.6633C15.2075 25.4595 15.1702 26.7125 14.3882 27.4619C13.6062 28.2113 12.3756 28.1733 11.6396 27.3771L0.5285 15.3569C-0.176167 14.5946 -0.176167 13.4054 0.5285 12.6431L11.6396 0.62292C12.3756 -0.173303 13.6062 -0.211271 14.3882 0.538115Z" fill="#0C0C0C"/></svg>
            </a>
            <div class="inner-container-main-checkout-right">

                @if($count === 0)
                    <div class="wc-empty-cart-message">
                        <div class="cart-empty woocommerce-info">Ваша корзина пуста.</div>
                    </div>
                    <p class="return-to-shop">
                        <a class="btn add-cart-link" href="{{ route('catalog.all') }}">Вернуться в магазин</a>
                    </p>
                @else
                {{-- Оформление отвечает back()-ом с причиной отказа (самовывоз недоступен,
                     оплата при получении не для этого способа, доставка не посчиталась), но
                     показать её было негде — покупатель просто возвращался на ту же страницу. --}}
                @if(session('error') || $errors->any())
                    {{-- Класс темы .woocommerce-error не берём: у эталона он скрыт
                         display:none !important, блок был бы невидим. --}}
                    <ul class="checkout-errors" role="alert">
                        @if(session('error'))
                            <li>{{ session('error') }}</li>
                        @endif
                        @foreach($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                @endif
                <form name="checkout" method="post" class="checkout woocommerce-checkout" action="{{ route('checkout.store') }}" enctype="multipart/form-data">
                    @csrf
                    <input type="hidden" name="pvz_code" id="pvz_code_input" value="">
                    <input type="hidden" name="pvz_address" id="pvz_address_input" value="">

                    <div class="ordering-checkout-left">

                        <div class="cart-main-top">
                            <p class="cart-main-top__title">товаров: {{ $count }}</p>
                        </div>

                        @foreach($items as $item)
                            @php
                                $image = $item->product->images->first();
                                $url = route('product.show', $item->product);
                            @endphp
                            <div class="cart-main-item cart_item" data-variant-id="{{ $item->id }}">
                                <div class="cart-main-item-left">
                                    <a href="{{ $url }}" class="cart-main-item__img">
                                        @if($image)
                                            <div style="background: url('{{ \App\Support\ImageVariants::url($image->path, 400) }}') no-repeat top / cover;"></div>
                                        @else
                                            <div></div>
                                        @endif
                                    </a>
                                    <div class="cart-main-item-settings">
                                        <a href="{{ $url }}" class="cart-main-item-settings__title">{{ $item->product->name }}</a>
                                        <div class="cart-item-variation">
                                            @foreach($item->attributeValues->sortBy('attribute_id') as $value)
                                                <p>{{ $value->attribute->name ?? 'Размер' }}: <span>{{ $value->label }}</span></p>
                                            @endforeach
                                        </div>
                                        <div class="product-quantity" data-title="Количество">
                                            <div class="quantity">
                                                {{-- Вид «−»/«+» рисуется фоновой иконкой из CSS темы, поэтому value пустой — как в готовом DOM эталона. --}}
                                                <input type="button" value="" class="minus button wp-element-button">
                                                <input type="number" class="input-text qty text" step="1" min="0"
                                                       max="{{ $item->stock_qty }}" name="cart[{{ $item->id }}][qty]"
                                                       value="{{ $item->qty }}" title="Qty" inputmode="numeric">
                                                <input type="button" value="" class="plus button wp-element-button">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="product-remove-price">
                                    <a href="javascript:void(0);" class="remove" aria-label="Удалить товар «{{ $item->product->name }}» из корзины">&times;</a>
                                    <div class="product-subtotal" data-title="Подытог">
                                        <span class="woocommerce-Price-amount amount"><bdi>{{ $price($item->lineTotal) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        <div class="col2-set" id="customer_details">

                            <p class="billing-title-names">способ доставки:</p>

                            <div class="block-fake-adress__active">
                                <p class="form-row address-field address-field_city woocommerce-validated">
                                    <input type="text" class="input-text input-city-doc" name="city" id="city_input"
                                           placeholder="Выбрать город*" value="{{ old('city') }}" autocomplete="address-level2">
                                </p>
                            </div>
                            <ul id="city-list" class="suggest-list" style="display: none;"></ul>

                            <ul class="col-01">
                                @foreach($shippingMethods as $method)
                                    @php
                                        // Самовывоз выключается, когда товара нет на складе выдачи:
                                        // тот же расчёт уже сделан в контроллере ($pickupAvailable).
                                        $methodDisabled = $method->kind() === 'pickup' && ! $pickupAvailable;
                                    @endphp
                                    <li @class(['shipping-method--disabled' => $methodDisabled])>
                                        <input type="radio" name="shipping_method" class="shipping_method"
                                               id="shipping_method_{{ $method->code }}" value="{{ $method->code }}"
                                               data-cod-allowed="{{ $method->cod_allowed ? '1' : '0' }}"
                                               data-needs-address="{{ in_array($method->code, $needsAddress, true) ? '1' : '0' }}"
                                               data-needs-pvz="{{ $method->needsPickupPoint() ? '1' : '0' }}"
                                               @disabled($methodDisabled)
                                               {{ $selectedMethod && $selectedMethod->code === $method->code ? 'checked' : '' }}>
                                        <label for="shipping_method_{{ $method->code }}">
                                            {{ $method->title }}
                                            <span>
                                                <br>
                                                @if($methodDisabled)
                                                    <p class="gray-days">Недоступен: товара нет на складе самовывоза. Выберите доставку.</p>
                                                @elseif($method->code === 'pickup')
                                                    <p class="pickup-title">{{ $method->config['address'] ?? '' }}</p>
                                                    @php
                                                        // Часы и телефон пункта правятся в админке: «Способы доставки» →
                                                        // «Самовывоз». Телефон пустой — берётся общий из настроек контактов.
                                                        $pickupHours = $method->config['hours'] ?? 'Ежедневно 11.00 - 20.00';
                                                        $pickupPhone = $method->config['phone'] ?? $setting('contact_phone', '+7 (995) 904 54 09');
                                                    @endphp
                                                    <div class="pickup-method">
                                                        @if($pickupHours)
                                                            <p class="gray-days">{{ $pickupHours }}</p>
                                                        @endif
                                                        @if($pickupPhone)
                                                            <p class="gray-days">Тел: <a href="tel:{{ preg_replace('/[^\d+]/', '', $pickupPhone) }}" class="gray-days">{{ $pickupPhone }}</a></p>
                                                        @endif
                                                    </div>
                                                @elseif($method->needsPickupPoint())
                                                    <p class="gray-days pvz-hint">Укажите город и выберите пункт выдачи{{ $method->provider() === 'cdek' ? ' на карте' : '' }}.</p>
                                                    <div class="pvz-choose">
                                                        <a href="javascript:void(0);" class="showcoupon-trigger pvz-open-btn"
                                                           data-provider="{{ $method->provider() }}" data-method="{{ $method->code }}">Выбрать ПВЗ</a>
                                                        <p class="pickup-title pvz-chosen-info" style="display: none;"></p>
                                                        @if($method->provider() === 'yandex')
                                                            {{-- У Яндекс Доставки нет готового виджета карты для сайта,
                                                                 поэтому пункты выбираются списком с поиском по адресу. --}}
                                                            <div class="yandex-pvz" style="display: none;">
                                                                <input type="text" class="input-text yandex-pvz__search" placeholder="Поиск по адресу">
                                                                <ul class="yandex-pvz__list"></ul>
                                                            </div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <p class="gray-days">После оформления заказа с вами свяжется менеджер для уточнения деталей доставки.</p>
                                                @endif
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="col-1">
                                <div class="woocommerce-billing-fields">
                                    <p class="billing-title-names">Контактные данные:</p>
                                    <div class="woocommerce-billing-fields__field-wrapper">
                                        <p class="form-row form-row-first validate-required" id="billing_first_name_field">
                                            <label for="billing_first_name">Имя<abbr class="required" title="обязательно">*</abbr></label>
                                            <span class="woocommerce-input-wrapper">
                                                <input type="text" class="input-text form-control margin-bottom-md" name="first_name"
                                                       id="billing_first_name" placeholder="Имя*" value="{{ old('first_name') }}"
                                                       autocomplete="given-name" required aria-required="true">
                                            </span>
                                        </p>
                                        <p class="form-row form-row-last validate-required" id="billing_last_name_field">
                                            <label for="billing_last_name">Фамилия<abbr class="required" title="обязательно">*</abbr></label>
                                            <span class="woocommerce-input-wrapper">
                                                <input type="text" class="input-text form-control margin-bottom-md" name="last_name"
                                                       id="billing_last_name" placeholder="Фамилия*" value="{{ old('last_name') }}"
                                                       autocomplete="family-name" required aria-required="true">
                                            </span>
                                        </p>
                                        <p class="form-row form-row-last validate-email" id="billing_email_field">
                                            <label for="billing_email">Email</label>
                                            <span class="woocommerce-input-wrapper">
                                                <input type="email" class="input-text form-control margin-bottom-md" name="email"
                                                       id="billing_email" placeholder="E-mail*" value="{{ old('email') }}"
                                                       autocomplete="email">
                                            </span>
                                        </p>
                                        <p class="form-row form-row-first validate-required validate-phone" id="billing_phone_field">
                                            <label for="billing_phone">Телефон<abbr class="required" title="обязательно">*</abbr></label>
                                            <span class="woocommerce-input-wrapper">
                                                <input type="tel" class="input-text form-control margin-bottom-md" name="phone"
                                                       id="billing_phone" placeholder="Телефон*" value="{{ old('phone') }}"
                                                       autocomplete="tel" required aria-required="true">
                                            </span>
                                        </p>
                                        <p class="form-row margin-bottom-md" id="billing_country_field">
                                            <label for="billing_country">Страна/регион</label>
                                            <span class="woocommerce-input-wrapper">
                                                <strong>Россия</strong>
                                                <input type="hidden" name="country" id="billing_country" value="RU" readonly>
                                            </span>
                                        </p>
                                        <p class="form-row rowcol-sm-4margin-y-md" id="billing_state_field">
                                            <label for="billing_state">Область / район</label>
                                            <span class="woocommerce-input-wrapper">
                                                <input type="text" class="input-text form-control margin-bottom-md" name="region"
                                                       id="billing_state" placeholder="Область/район" value="{{ old('region') }}"
                                                       autocomplete="address-level1">
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <p class="billing-title-names">способы оплаты:</p>
                            <ul class="col-03">
                                <li class="wc_payment_method payment_method_cod">
                                    <input type="radio" class="input-radio" name="payment_method" id="payment_method_cod" value="cod" checked>
                                    <label for="payment_method_cod">Оплата наличными / оплата по QR-коду при получении</label>
                                </li>
                                {{-- Онлайн-эквайеры и их названия задаются в админке
                                     («Настройки → Способы оплаты»), поэтому список не зашит в шаблон. --}}
                                @foreach($onlinePaymentMethods as $method)
                                    <li class="wc_payment_method payment_method_{{ $method->key }}">
                                        <input type="radio" class="input-radio" name="payment_method" id="payment_method_{{ $method->key }}" value="{{ $method->key }}" data-online="1">
                                        <label for="payment_method_{{ $method->key }}">{{ $method->name }}</label>
                                        {{-- Официальные бейджи Яндекса: сами считают размер платежа в Сплит и
                                             кешбэк по сумме заказа, поэтому цифры не могут разойтись с тем, что
                                             покупатель увидит на оплате. Сумма обновляется из refreshTotals. --}}
                                        @if($yandexPayMerchantId && in_array($method->key, ['yandex_pay', 'yandex_split'], true))
                                            <span class="payment-badge">
                                                <yandex-pay-badge
                                                    type="{{ $method->key === 'yandex_split' ? 'bnpl' : 'cashback' }}"
                                                    amount="{{ number_format((float) $totals['total'], 2, '.', '') }}"
                                                    size="m"
                                                    color="primary"
                                                    merchant-id="{{ $yandexPayMerchantId }}"></yandex-pay-badge>
                                            </span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <div class="col-2">
                                <div class="woocommerce-additional-fields">
                                    <div class="woocommerce-additional-fields__field-wrapper">
                                        <p class="form-row notes" id="order_comments_field">
                                            <label for="order_comments">Комментарий к заказу: <span class="optional">(необязательно)</span></label>
                                            <span class="woocommerce-input-wrapper">
                                                <textarea name="comment" class="input-text form-control margin-bottom-md" id="order_comments" placeholder="Комментарий" rows="2" cols="5">{{ old('comment') }}</textarea>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    <div class="ordering-checkout-right">
                        <div class="ordering-checkout-right-item">
                            <h3 id="order_review_heading" class="ur-order-title">Ваш заказ</h3>
                            <div id="order_review" class="woocommerce-checkout-review-order">
                                <div class="review-pay-order">
                                    <div class="review-pay-order-item">
                                        <p>ТОВАРЫ ({{ $count }}):</p>
                                        <p><span><strong><span class="woocommerce-Price-amount amount" id="subtotal-display"><bdi>{{ $price($totals['subtotal']) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></strong></span></p>
                                    </div>
                                    <div class="review-pay-order-item review-pay-order-item_discount" @if($totals['discount'] <= 0) style="display: none;" @endif>
                                        <p>СКИДКА:</p>
                                        <p><span><span class="woocommerce-Price-amount amount" id="discount-display"><bdi>−{{ $price($totals['discount']) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></span></p>
                                    </div>
                                    <div class="review-pay-order-item review-pay-order-item_shipping">
                                        {{-- Примерный срок от перевозчика: приходит тем же ответом, что и цена.
                                             У способов без интеграции берётся из поля в админке. --}}
                                        <p>ДОСТАВКА:<span class="shipping-days" id="shipping-days" @if(empty($shippingDays)) style="display: none;" @endif>{{ $shippingDays }}</span></p>
                                        <p><span><span class="woocommerce-Price-amount amount" id="shipping-display"><bdi>@if($shippingUnknown ?? false)—@else{{ $price($totals['shipping']) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span>@endif</bdi></span></span></p>
                                    </div>
                                    <div class="review-pay-order-item review-pay-order-item_gift" @if($totals['gift_used'] <= 0) style="display: none;" @endif>
                                        <p>СЕРТИФИКАТ:</p>
                                        <p><span><span class="woocommerce-Price-amount amount" id="gift-display"><bdi>−{{ $price($totals['gift_used']) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></span></p>
                                    </div>
                                    <div class="review-pay-order-LastItem">
                                        <p>ИТОГО:</p>
                                        <p><span><strong><span class="woocommerce-Price-amount amount" id="total-display"><bdi>{{ $price($totals['total']) }}&nbsp;<span class="woocommerce-Price-currencySymbol">&#8381;</span></bdi></span></strong></span></p>
                                    </div>

                                    <div class="review-pay-order__promo">
                                        <a href="javascript:void(0);" class="showcoupon-trigger">Ввести промокод</a>
                                        <div class="review-pay-promo-show @if($totals['coupon']) active @endif">
                                            <div class="input-coupon-label">
                                                <input type="text" name="coupon-trigger" class="input-coupon" placeholder="Код купона"
                                                       value="{{ $totals['coupon']?->code }}" data-target="{{ route('checkout.coupon') }}">
                                            </div>
                                            <p class="coupon-trigger-btn">Применить</p>
                                        </div>
                                    </div>
                                    <div class="review-pay-order__gift-card">
                                        <a href="javascript:void(0);" class="showcoupon-trigger">Gift card</a>
                                        <div class="review-pay-promo-show @if($totals['gift']) active @endif">
                                            <div class="input-coupon-label">
                                                <input type="text" name="coupon-trigger" class="input-coupon" placeholder="Номер"
                                                       value="{{ $totals['gift']?->code }}" data-target="{{ route('checkout.gift-certificate') }}">
                                            </div>
                                            <p class="coupon-trigger-btn">Применить</p>
                                        </div>
                                    </div>
                                </div>

                                <div id="payment" class="woocommerce-checkout-payment">
                                    <div class="form-row place-order">
                                        <button type="submit" class="btn add-cart-link button alt" name="checkout_place_order" id="place_order" value="Оформить заказ" data-value="Оформить заказ">Оформить заказ</button>

                                        {{-- Поля адреса лежат здесь, как у эталона: скрипт переносит этот блок
                                             внутрь выбранного способа доставки, если тому нужен адрес. --}}
                                        <div class="block-fake-adress">
                                            <p class="form-row address-field address-field_street">
                                                <input type="text" name="street" placeholder="Улица*" value="{{ old('street') }}" autocomplete="off">
                                                {{-- Подсказки улиц: разметка и стили те же, что у списка городов. --}}
                                                <ul id="street-list" class="suggest-list" style="display: none;"></ul>
                                            </p>
                                            <p class="form-row address-field address-field_house">
                                                <input type="text" name="house" placeholder="Дом*" value="{{ old('house') }}" autocomplete="off">
                                                {{-- Номера домов на выбранной улице. --}}
                                                <ul id="house-list" class="suggest-list" style="display: none;"></ul>
                                            </p>
                                            <p class="form-row address-field address-field_room">
                                                <input type="text" name="room" placeholder="Квартира/Офис*" value="{{ old('room') }}">
                                            </p>
                                        </div>

                                        <p class="review-pay-order__policy">Нажимая на кнопку "Оформить заказ" я подтверждаю своё согласие с <a href="{{ url('/offer-and-privacy-policy') }}">оферта и политика конфеденциальности</a></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
                @endif

            </div>
        </div>
    </div>
</div>

{{-- Карта выбора пункта выдачи Яндекса. Лежит вне блока способов доставки:
     скрипт эталона переставляет колонки оформления, а модалке это безразлично. --}}
<div class="ymap-modal" id="ymap-modal" style="display: none;">
    <div class="ymap-modal__box">
        <div class="ymap-modal__head">
            <span class="ymap-modal__title">Выберите пункт выдачи</span>
            <a href="javascript:void(0);" class="ymap-modal__close" id="ymap-close">✕</a>
        </div>
        <div class="ymap-modal__body">
            <div class="ymap-modal__map" id="ymap-canvas"></div>
            <div class="ymap-modal__side">
                <input type="text" class="input-text ymap-modal__search" id="ymap-search" placeholder="Поиск по адресу">
                <ul class="ymap-modal__list" id="ymap-list"></ul>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
@if($count > 0)
@php
    // Виджет карты СДЭК грузим только когда способ СДЭК действительно включён:
    // иначе выключенная интеграция всё равно тянула бы внешний скрипт на страницу оплаты.
    $cdekPvzEnabled = $shippingMethods->contains(fn ($method) => $method->provider() === 'cdek' && $method->needsPickupPoint());
@endphp
@php
    $yandexPvzEnabled = $shippingMethods->contains(fn ($method) => $method->provider() === 'yandex' && $method->needsPickupPoint());
@endphp
@if($yandexMapApiKey && $yandexPvzEnabled)
    {{-- Карта пунктов выдачи Яндекса: своя, потому что готового виджета для сайта
         у Яндекс Доставки нет. Домен российский, без VPN открывается. --}}
    <script src="https://api-maps.yandex.ru/2.1/?apikey={{ urlencode($yandexMapApiKey) }}&lang=ru_RU"></script>
@endif
@if($yandexMapApiKey && $cdekPvzEnabled)
    <script>window.cdekMapApiKey = @json($yandexMapApiKey);</script>
    {{-- Версия зафиксирована, а не rolling-тег: скрипт работает на странице оплаты
         и получает ключ карты, тихое обновление здесь нам не нужно. --}}
    <script src="https://cdn.jsdelivr.net/gh/cdek-it/widget@3.11.1/dist/cdek-widget.umd.js"></script>
@endif
@if($yandexPayMerchantId)
    {{-- Рисует бейджи «N × платёж в Сплит» и «баллы Плюса» рядом со способами
         оплаты. Домен российский, без VPN открывается. --}}
    <script src="https://pay.yandex.ru/sdk/v1/pay.js"></script>
@endif
<script>
(function () {
    const routes = {
        quote: @json(route('checkout.quote')),
        cartUpdate: @json(route('cart.update')),
        cartRemove: @json(route('cart.remove')),
        pickupPoints: @json(route('checkout.pickup-points')),
        yandexPickupPoints: @json(route('checkout.yandex-pickup-points')),
        cities: @json(route('checkout.cities')),
        streets: @json(route('checkout.streets')),
    };
    const csrf = @json(csrf_token());

    const form = document.querySelector('form.checkout');
    if (!form) return;

    const cityInput = document.getElementById('city_input');
    const addressBlock = document.querySelector('.block-fake-adress');
    const placeOrderRow = document.querySelector('.form-row.place-order');
    const pvzCodeInput = document.getElementById('pvz_code_input');
    const pvzAddressInput = document.getElementById('pvz_address_input');

    async function post(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        });
        return { status: res.status, json: await res.json().catch(() => ({})) };
    }

    function selectedMethod() {
        return document.querySelector('input[name="shipping_method"]:checked');
    }

    function money(value) {
        return Math.round(value) + ' ';
    }

    // Разметка суммы у эталона — <bdi>ЧИСЛО&nbsp;<span>₽</span></bdi>, поэтому
    // подменяется только текстовый узел перед знаком рубля, а не весь блок.
    function setAmount(id, value, negative) {
        const el = document.getElementById(id);
        if (!el) return;
        const bdi = el.querySelector('bdi');
        // Неизвестная сумма (доставка ещё не рассчитана) — прочерк без знака рубля,
        // ноль здесь читался бы как «доставка бесплатная». Знак рубля при этом из
        // разметки исчезает, поэтому обратно он создаётся, а не только переносится.
        if (value === null) {
            bdi.textContent = '—';
            return;
        }
        let symbol = bdi.querySelector('.woocommerce-Price-currencySymbol');
        if (!symbol) {
            symbol = document.createElement('span');
            symbol.className = 'woocommerce-Price-currencySymbol';
            symbol.textContent = '₽';
        }
        bdi.textContent = (negative ? '−' : '') + money(value);
        bdi.appendChild(symbol);
    }

    // Пока перевозчик не назвал цену, оформлять нечего: сервер такой заказ всё равно
    // отклонит, поэтому кнопка гасится и рядом пишется, чего не хватает.
    function setShippingUnknown(unknown) {
        const button = document.getElementById('place_order');
        if (button) {
            button.disabled = unknown;
            button.classList.toggle('is-disabled', unknown);
        }
        let note = document.getElementById('shipping-unknown-note');
        if (unknown && !note) {
            note = document.createElement('p');
            note.id = 'shipping-unknown-note';
            note.className = 'shipping-unknown-note';
            button?.insertAdjacentElement('beforebegin', note);
        }
        if (note) {
            const method = selectedMethod();
            note.textContent = method && method.dataset.needsPvz === '1'
                ? 'Выберите пункт выдачи — после этого посчитаем доставку.'
                : 'Укажите город и адрес — после этого посчитаем доставку.';
            note.style.display = unknown ? '' : 'none';
        }
    }

    function setShippingDays(days) {
        const box = document.getElementById('shipping-days');
        if (!box) return;
        box.textContent = days || '';
        box.style.display = days ? '' : 'none';
    }

    function applyTotals(t) {
        setAmount('subtotal-display', t.subtotal, false);
        setAmount('shipping-display', t.shipping_cost, false);
        setShippingDays(t.shipping_days);
        setShippingUnknown(!!t.shipping_unknown);
        setAmount('discount-display', t.discount, true);
        setAmount('gift-display', t.gift_used, true);
        setAmount('total-display', t.total, false);
        document.querySelector('.review-pay-order-item_discount').style.display = t.discount > 0 ? '' : 'none';
        document.querySelector('.review-pay-order-item_gift').style.display = t.gift_used > 0 ? '' : 'none';
        updatePaymentBadges(t.total);
    }

    // Бейджи Яндекса считают платёж от суммы в атрибуте amount — она обязана
    // ехать за итогом заказа, иначе после смены доставки или промокода на них
    // останется старая цифра.
    function updatePaymentBadges(total) {
        document.querySelectorAll('yandex-pay-badge').forEach(badge => {
            badge.setAttribute('amount', Number(total).toFixed(2));
        });
    }

    function addressLine() {
        const parts = ['street', 'house', 'room']
            .map(name => form.querySelector(`[name="${name}"]`)?.value.trim())
            .filter(Boolean);
        return parts.length ? parts.join(', ') : null;
    }

    async function refreshTotals() {
        const method = selectedMethod();
        const { status, json } = await post(routes.quote, {
            shipping_method: method ? method.value : null,
            city: cityInput.value.trim() || null,
            // Яндекс Доставка считает цену не по городу, а по конкретной точке:
            // выбранному пункту выдачи либо адресу получателя.
            pvz_code: pvzCodeInput.value || null,
            address: addressLine(),
        });
        if (status === 200 && json.ok) applyTotals(json);
    }

    // Поля адреса переносятся внутрь выбранного способа доставки, как это делает
    // скрипт эталона: в ul.col-01 у блока свой вид (CSS темы), а вне списка он
    // спрятан, поэтому «ненужному» способу поля не показываются вообще.
    function moveAddressBlock() {
        const method = selectedMethod();
        if (!method) return;
        const needs = method.dataset.needsAddress === '1';
        const target = needs ? method.closest('li') : placeOrderRow;
        if (addressBlock.parentElement !== target) target.appendChild(addressBlock);
    }

    function togglePickupDetails() {
        const method = selectedMethod();
        document.querySelectorAll('.pickup-method').forEach(el => {
            el.classList.toggle('active', !!method && method.closest('li').contains(el));
        });
    }

    function updatePaymentAvailability() {
        const method = selectedMethod();
        const codAllowed = !!method && method.dataset.codAllowed === '1';
        const cod = document.getElementById('payment_method_cod');
        // Онлайн-способов может быть несколько и набор задаётся в админке, поэтому
        // подменять выбор нужно на первый доступный, а не на конкретный эквайер.
        const firstOnline = document.querySelector('input[name="payment_method"][data-online]');
        cod.disabled = !codAllowed;
        cod.closest('li').classList.toggle('is-disabled', !codAllowed);
        if (!codAllowed && cod.checked && firstOnline) firstOnline.checked = true;
    }

    /* ---------- пункты выдачи: карта СДЭК или список Яндекса ---------- */

    let cdekWidget = null;
    let currentPoints = [];
    let yandexPoints = [];
    // Пункты грузятся сразу после ввода города, но человек может нажать «Выбрать ПВЗ»
    // раньше, чем ответ пришёл — тогда карта открывается сама, как только он придёт.
    let pvzOpenRequested = false;

    // Кнопка «Выбрать ПВЗ» есть у каждого способа с пунктами выдачи, поэтому
    // работаем не с одним элементом, а с кнопкой выбранного способа.
    function pvzUi() {
        const method = selectedMethod();
        const li = method?.closest('li');
        return {
            method,
            provider: li?.querySelector('.pvz-open-btn')?.dataset.provider || null,
            button: li?.querySelector('.pvz-open-btn') || null,
            info: li?.querySelector('.pvz-chosen-info') || null,
            list: li?.querySelector('.yandex-pvz') || null,
        };
    }

    function resetPvz() {
        pvzCodeInput.value = '';
        pvzAddressInput.value = '';
        document.querySelectorAll('.pvz-chosen-info').forEach(el => {
            el.style.display = 'none';
            el.textContent = '';
        });
        document.querySelectorAll('.pvz-open-btn').forEach(el => { el.textContent = 'Выбрать ПВЗ'; });
        document.querySelectorAll('.yandex-pvz').forEach(el => { el.style.display = 'none'; });
        cdekWidget?.clearSelection?.();
    }

    function choosePoint(code, address) {
        const ui = pvzUi();
        pvzCodeInput.value = code;
        pvzAddressInput.value = address;
        if (ui.info) {
            ui.info.textContent = address;
            ui.info.style.display = '';
        }
        if (ui.button) ui.button.textContent = 'Выбрать другой ПВЗ';
        if (ui.list) ui.list.style.display = 'none';
        closeYandexMap();
        refreshTotals();
    }

    async function loadPickupPoints() {
        const ui = pvzUi();
        const city = cityInput.value.trim();
        if (!city || !ui.button) return;
        resetPvz();
        ui.button.textContent = 'Загрузка пунктов выдачи…';

        if (ui.provider === 'yandex') {
            const { json } = await post(routes.yandexPickupPoints, { city });
            yandexPoints = json.points || [];
            if (!json.ok || !yandexPoints.length) {
                ui.button.textContent = 'Нет пунктов выдачи Яндекса в этом городе';
                pvzOpenRequested = false;
                return;
            }
            ui.button.textContent = 'Выбрать ПВЗ';
            renderYandexPoints('');
            if (pvzOpenRequested) {
                pvzOpenRequested = false;
                openPvz();
            }
            return;
        }

        const { json } = await post(routes.pickupPoints, { city });
        currentPoints = json.points || [];
        if (!json.ok || !currentPoints.length) {
            ui.button.textContent = 'Нет пунктов выдачи СДЭК в этом городе';
            return;
        }
        ui.button.textContent = 'Выбрать ПВЗ';
        cdekWidget?.updateOffices?.(currentPoints);
    }

    /* ---------- карта пунктов выдачи Яндекса ---------- */

    const ymapModal = document.getElementById('ymap-modal');
    const ymapSearch = document.getElementById('ymap-search');
    const ymapList = document.getElementById('ymap-list');
    let ymap = null;
    let ymapCollection = null;

    function pointsMatching(query) {
        const needle = query.trim().toLowerCase();
        return yandexPoints.filter(point => !needle
            || point.address.toLowerCase().includes(needle)
            || point.name.toLowerCase().includes(needle));
    }

    // Список в модалке (он же — весь выбор, если ключ карт не задан).
    function renderYandexPoints(query) {
        const matched = pointsMatching(query).slice(0, 80);
        const html = matched.length
            ? matched.map(point => `<li data-id="${point.id}" data-address="${point.address.replace(/"/g, '&quot;')}">
                    <strong>${point.name}</strong><span>${point.address}</span>
                 </li>`).join('')
            : '<li class="yandex-pvz__empty">Ничего не найдено</li>';

        if (ymapList) ymapList.innerHTML = html;

        const inline = pvzUi().list?.querySelector('.yandex-pvz__list');
        if (inline) inline.innerHTML = html;
    }

    // Метки на карте: точек в крупном городе больше тысячи, поэтому кластеризуем.
    function renderYandexMarks(query) {
        if (!ymap || !window.ymaps) return;
        ymapCollection?.removeAll();

        const marks = [];
        let minLat = 90, maxLat = -90, minLon = 180, maxLon = -180;

        pointsMatching(query).forEach(point => {
            if (!point.latitude || !point.longitude) return;
            marks.push(new ymaps.Placemark([point.latitude, point.longitude], {
                balloonContentHeader: point.name,
                balloonContentBody: `<p>${point.address}</p>${point.comment ? `<p style="color:#6b6b6b">${point.comment}</p>` : ''}`
                    + `<button type="button" class="ymap-choose" data-id="${point.id}" data-address="${point.address.replace(/"/g, '&quot;')}">Выбрать этот пункт</button>`,
                hintContent: point.address,
            }, { preset: 'islands#blackDotIcon' }));
            minLat = Math.min(minLat, point.latitude);
            maxLat = Math.max(maxLat, point.latitude);
            minLon = Math.min(minLon, point.longitude);
            maxLon = Math.max(maxLon, point.longitude);
        });

        ymapCollection.add(marks);

        // Границы считаем сами: Clusterer.getBounds() сразу после add() отдаёт null,
        // а setBounds(null) обрывает отрисовку карты ошибкой внутри API Яндекса.
        try {
            if (marks.length > 1) {
                ymap.setBounds([[minLat, minLon], [maxLat, maxLon]], { checkZoomRange: true, zoomMargin: 30 });
            } else if (marks.length === 1) {
                ymap.setCenter([minLat, minLon], 14);
            }
        } catch (e) {
            // Не критично: карта останется в исходном положении.
        }
    }

    function openYandexMap() {
        if (!window.ymaps || !ymapModal) return false;

        ymapModal.style.display = '';

        ymaps.ready(() => {
            if (!ymap) {
                ymap = new ymaps.Map('ymap-canvas', { center: [55.751244, 37.618423], zoom: 10, controls: ['zoomControl', 'geolocationControl'] });
                ymapCollection = new ymaps.Clusterer({ preset: 'islands#blackClusterIcons', groupByCoordinates: false });
                ymap.geoObjects.add(ymapCollection);
            }
            renderYandexMarks(ymapSearch.value);
            // Карта, отрисованная в скрытом контейнере, не знает своих размеров.
            ymap.container.fitToViewport();

            setTimeout(() => {
                const tiles = performance.getEntriesByType('resource')
                    .filter(entry => /core-renderer-tiles|tiles\?/.test(entry.name));
                const note = document.getElementById('ymap-blocked');
                if (note) note.style.display = tiles.length ? 'none' : '';
            }, 5000);
        });

        return true;
    }

    function closeYandexMap() {
        if (ymapModal) ymapModal.style.display = 'none';
    }

    document.getElementById('ymap-close')?.addEventListener('click', closeYandexMap);
    ymapModal?.addEventListener('click', (event) => {
        if (event.target === ymapModal) closeYandexMap();
    });

    ymapSearch?.addEventListener('input', () => {
        renderYandexPoints(ymapSearch.value);
        renderYandexMarks(ymapSearch.value);
    });

    function openPvz() {
        const ui = pvzUi();

        if (ui.provider === 'yandex') {
            if (!yandexPoints.length) {
                // Точки ещё не пришли: помечаем, что человек уже ждёт карту.
                pvzOpenRequested = true;
                if (ui.button) ui.button.textContent = 'Загрузка пунктов выдачи…';
                loadPickupPoints();
                return;
            }
            renderYandexPoints(ymapSearch ? ymapSearch.value : '');
            // Карта — основной способ выбора; список внутри способа остаётся
            // запасным вариантом, если ключ Яндекс.Карт не задан.
            if (!openYandexMap() && ui.list) ui.list.style.display = '';
            return;
        }

        if (!window.CDEKWidget || !window.cdekMapApiKey || !currentPoints.length) return;
        if (!cdekWidget) {
            cdekWidget = new window.CDEKWidget({
                apiKey: window.cdekMapApiKey,
                popup: true,
                defaultLocation: cityInput.value.trim(),
                officesRaw: currentPoints,
                hideDeliveryOptions: { door: true },
                onChoose: (_type, _tariff, address) => choosePoint(address.code, address.name),
            });
        } else {
            cdekWidget.updateOffices(currentPoints);
        }
        cdekWidget.open();
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('.pvz-open-btn')) {
            openPvz();
            return;
        }

        const fromBalloon = event.target.closest('.ymap-choose');
        if (fromBalloon) {
            choosePoint(fromBalloon.dataset.id, fromBalloon.dataset.address);
            return;
        }

        const item = event.target.closest('.yandex-pvz__list li[data-id], .ymap-modal__list li[data-id]');
        if (item) choosePoint(item.dataset.id, item.dataset.address);
    });

    document.querySelectorAll('.yandex-pvz__search').forEach(input => {
        input.addEventListener('input', () => renderYandexPoints(input.value));
    });

    /* ---------- способ доставки и город ---------- */

    document.querySelectorAll('input[name="shipping_method"]').forEach(radio => {
        radio.addEventListener('change', () => {
            moveAddressBlock();
            togglePickupDetails();
            updatePaymentAvailability();
            refreshTotals();
            if (radio.dataset.needsPvz === '1') loadPickupPoints(); else resetPvz();
        });
    });

    /* ---------- подсказки адреса ---------- */

    // Один компонент на все три поля (город, улица, дом): выпадающий список под
    // полем, выбор мышью или с клавиатуры (стрелки/Enter/Esc), в строке жирным
    // выделено то, что человек уже набрал, серым — уточнение (регион, район).
    // Ответы приходят не в том порядке, в котором отправлены запросы, поэтому
    // применяется только самый свежий (seq).
    function attachSuggest({ input, list, minLength, fetchItems, onPick }) {
        if (!input || !list) return { hide: () => {} };

        let seq = 0;
        let items = [];
        let active = -1;
        let timer;

        function hide() {
            list.style.display = 'none';
            list.innerHTML = '';
            items = [];
            active = -1;
            input.removeAttribute('aria-expanded');
        }

        // Совпавшую часть подсвечиваем, но текст кладём узлами, а не innerHTML:
        // названия приходят из внешнего источника и в разметку попадать не должны.
        function highlight(text, query) {
            const at = query ? text.toLowerCase().indexOf(query.toLowerCase()) : -1;
            const box = document.createElement('span');
            box.className = 'suggest-item__main';

            if (at < 0) {
                box.textContent = text;
                return box;
            }

            const strong = document.createElement('strong');
            strong.textContent = text.slice(at, at + query.length);
            box.append(text.slice(0, at), strong, text.slice(at + query.length));

            return box;
        }

        function setActive(index) {
            const nodes = list.querySelectorAll('li');
            nodes.forEach(node => node.classList.remove('is-active'));

            if (index < 0 || index >= nodes.length) {
                active = -1;
                return;
            }

            active = index;
            nodes[index].classList.add('is-active');
            nodes[index].scrollIntoView({ block: 'nearest' });
        }

        function render(found, query) {
            items = found;
            list.innerHTML = '';

            found.forEach((item, index) => {
                const li = document.createElement('li');
                li.className = 'suggest-item';
                li.append(highlight(item.label, query));

                if (item.hint) {
                    const hint = document.createElement('span');
                    hint.className = 'suggest-item__hint';
                    hint.textContent = item.hint;
                    li.append(hint);
                }

                // mousedown, а не click: клик по списку сначала уводит фокус с
                // поля, и обработчик blur успел бы список спрятать.
                li.addEventListener('mousedown', event => {
                    event.preventDefault();
                    pick(index);
                });

                list.appendChild(li);
            });

            list.style.display = '';
            input.setAttribute('aria-expanded', 'true');
            setActive(-1);
        }

        function pick(index) {
            const item = items[index];
            if (!item) return;
            hide();
            onPick(item);
        }

        async function run(query) {
            const current = ++seq;
            const found = await fetchItems(query).catch(() => []);
            if (current !== seq) return;

            if (!found || !found.length) {
                hide();
                return;
            }

            render(found, query);
        }

        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const query = input.value.trim();

            if (query.length < minLength) {
                seq++; // ответ на предыдущий, уже неактуальный запрос не покажем
                hide();
                return;
            }

            timer = setTimeout(() => run(query), 300);
        });

        input.addEventListener('keydown', event => {
            if (list.style.display === 'none') return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const count = items.length;
                if (!count) return;
                setActive(event.key === 'ArrowDown'
                    ? (active + 1) % count
                    : (active <= 0 ? count - 1 : active - 1));
                return;
            }

            if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                pick(active);
                return;
            }

            if (event.key === 'Escape') hide();
        });

        input.addEventListener('blur', () => setTimeout(hide, 150));

        return { hide };
    }

    async function loadSuggestions(url, params) {
        const query = new URLSearchParams(params);
        const res = await fetch(url + '?' + query.toString(), {
            headers: { 'Accept': 'application/json' },
        }).catch(() => null);

        if (!res || !res.ok) return [];

        return await res.json().then(json => json.cities || json.addresses || []).catch(() => []);
    }

    // Город: по нему перевозчик ищет тариф, поэтому название должно быть из его
    // справочника — источник выбирает сервер (СДЭК, если он возит, иначе OSM).
    const citySuggest = attachSuggest({
        input: cityInput,
        list: document.getElementById('city-list'),
        minLength: 2,
        fetchItems: query => loadSuggestions(routes.cities, { q: query }),
        onPick: item => {
            cityInput.value = item.city;
            refreshTotals();
            if (selectedMethod()?.dataset.needsPvz === '1') loadPickupPoints();
        },
    });

    let cityTimer;
    cityInput.addEventListener('input', () => {
        clearTimeout(cityTimer);
        cityTimer = setTimeout(() => {
            refreshTotals();
            if (selectedMethod()?.dataset.needsPvz === '1') loadPickupPoints();
        }, 400);
    });

    // Яндекс Доставка курьером считает цену по адресу получателя, поэтому итоги
    // должны пересчитываться и при правке улицы/дома, а не только города.
    let addressTimer;
    ['street', 'house', 'room'].forEach(name => {
        const input = form.querySelector(`[name="${name}"]`);
        input?.addEventListener('input', () => {
            clearTimeout(addressTimer);
            addressTimer = setTimeout(refreshTotals, 600);
        });
    });

    // Подсказки улиц и домов для доставки курьером: перевозчик считает цену по
    // конкретному адресу, поэтому опечатка в улице стоит покупателю расчёта.
    const streetInput = form.querySelector('[name="street"]');
    const houseInput = form.querySelector('[name="house"]');
    const roomInput = form.querySelector('[name="room"]');

    attachSuggest({
        input: streetInput,
        list: document.getElementById('street-list'),
        minLength: 3,
        fetchItems: query => loadSuggestions(routes.streets, { q: query, city: cityInput.value.trim() }),
        onPick: item => {
            streetInput.value = item.street;
            // Номер дома подсказка приносит, только если человек набрал его прямо
            // в поле улицы («Тверская 12»), — уже введённый не затираем.
            if (item.house) houseInput.value = item.house;
            refreshTotals();
            // Дальше человеку всё равно в следующее пустое поле — переносим его туда сами.
            (item.house ? roomInput : houseInput)?.focus();
        },
    });

    // Номера домов подсказываются на уже выбранной улице: без неё «12» — это
    // запрос ко всему городу, и осмысленного ответа на него нет.
    attachSuggest({
        input: houseInput,
        list: document.getElementById('house-list'),
        minLength: 1,
        fetchItems: query => {
            const street = streetInput.value.trim();
            if (street.length < 3) return Promise.resolve([]);

            return loadSuggestions(routes.streets, { q: query, city: cityInput.value.trim(), street });
        },
        onPick: item => {
            houseInput.value = item.house;
            refreshTotals();
            roomInput?.focus();
        },
    });

    /* ---------- строки корзины ---------- */

    function patchRows(items) {
        document.querySelectorAll('.cart-main-item').forEach(row => {
            const item = items.find(i => String(i.variant_id) === row.dataset.variantId);
            if (!item) {
                row.remove();
                return;
            }
            row.querySelector('.qty').value = item.qty;
            const bdi = row.querySelector('.product-subtotal bdi');
            const symbol = bdi.querySelector('.woocommerce-Price-currencySymbol');
            bdi.textContent = money(item.line_total);
            bdi.appendChild(symbol);
        });
        const units = items.reduce((sum, i) => sum + i.qty, 0);
        document.querySelector('.cart-main-top__title').textContent = 'товаров: ' + units;
        document.querySelector('.review-pay-order-item p').textContent = 'ТОВАРЫ (' + units + '):';
    }

    // Страница не перезагружается на изменение количества: перезагрузка стирала бы
    // уже введённые имя/телефон/адрес. Пустая корзина — исключение, там сохранять нечего.
    async function applyCartResponse(json) {
        if (!json.ok) return;
        if (!json.items.length) {
            window.location.reload();
            return;
        }
        patchRows(json.items);
        await refreshTotals();
    }

    document.querySelectorAll('.cart-main-item').forEach(row => {
        const variantId = row.dataset.variantId;
        const qty = row.querySelector('.qty');
        const minus = row.querySelector('.minus');
        const plus = row.querySelector('.plus');
        const remove = row.querySelector('.remove');

        // Кнопки блокируются на время запроса: двойной быстрый клик иначе дважды
        // прочитал бы одно и то же старое количество.
        async function change(delta) {
            minus.disabled = plus.disabled = true;
            const next = Math.max(0, parseInt(qty.value, 10) + delta);
            const { json } = await post(routes.cartUpdate, { variant_id: variantId, qty: next });
            await applyCartResponse(json);
            minus.disabled = plus.disabled = false;
        }

        minus.addEventListener('click', () => change(-1));
        plus.addEventListener('click', () => change(1));
        qty.addEventListener('change', async () => {
            const { json } = await post(routes.cartUpdate, { variant_id: variantId, qty: Math.max(0, parseInt(qty.value, 10) || 0) });
            await applyCartResponse(json);
        });
        remove.addEventListener('click', async () => {
            const { json } = await post(routes.cartRemove, { variant_id: variantId });
            await applyCartResponse(json);
        });
    });

    /* ---------- промокод и подарочный сертификат ---------- */

    document.querySelectorAll('.review-pay-order__promo, .review-pay-order__gift-card').forEach(block => {
        const input = block.querySelector('.input-coupon');
        const label = block.querySelector('.input-coupon-label');
        const button = block.querySelector('.coupon-trigger-btn');

        button.addEventListener('click', async () => {
            const method = selectedMethod();
            const { json } = await post(input.dataset.target, {
                code: input.value.trim(),
                shipping_method: method ? method.value : null,
                city: cityInput.value.trim() || null,
            });
            if (!json.ok) return;
            // huy/hehuy — эталонные классы состояния поля: зелёная рамка с «Готово!»
            // и красная с «Промокод не найден» рисуются его же CSS.
            label.classList.remove('huy', 'hehuy');
            if (input.value.trim() !== '') label.classList.add(json.valid ? 'huy' : 'hehuy');
            applyTotals(json);
        });
    });

    /* ---------- отправка ---------- */

    form.addEventListener('submit', (e) => {
        if (selectedMethod()?.dataset.needsPvz === '1' && !pvzCodeInput.value) {
            e.preventDefault();
            if (typeof showToast === 'function') showToast('Выберите пункт выдачи');
            openPvz();
        }
    });

    moveAddressBlock();
    togglePickupDetails();
    updatePaymentAvailability();
    // Первая отрисовка приходит с сервера: если у способа по умолчанию цена доставки
    // ещё неизвестна, кнопку и подсказку надо привести в это же состояние сразу.
    setShippingUnknown(@json($shippingUnknown ?? false));
})();
</script>
@endif
@endpush
