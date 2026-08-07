{{--
    Официальный бейдж Яндекса: сам считает размер платежа в Сплит по сумме и сам
    прячется, если сумма вне лимитов Сплита — поэтому на дешёвых товарах его
    просто не будет, а неверных цифр он показать не может.

    Параметры: $amount (рубли), $type (bnpl|cashback), $size (s|m|l).
--}}
@php
    $badgeMerchantId = \App\Models\SiteSetting::get('yandex_pay_merchant_id', config('services.yandex_pay.merchant_id'));
@endphp

@if($badgeMerchantId && (float) $amount > 0)
    <span class="payment-badge">
        <yandex-pay-badge
            type="{{ $type ?? 'bnpl' }}"
            amount="{{ number_format((float) $amount, 2, '.', '') }}"
            size="{{ $size ?? 'm' }}"
            color="primary"
            merchant-id="{{ $badgeMerchantId }}"></yandex-pay-badge>
    </span>

    {{-- Скрипт нужен один на страницу, сколько бы бейджей на ней ни было. --}}
    @once
        @push('scripts')
            <script src="https://pay.yandex.ru/sdk/v1/pay.js"></script>
        @endpush
    @endonce
@endif
