@extends('layouts.app')

@section('content')
    <div class="container" style="padding:60px 0;text-align:center;max-width:600px;">
        <h1 style="text-transform:uppercase;font-size:24px;margin-bottom:16px;">Не получилось оплатить заказ</h1>
        <p style="color:var(--color-muted);">Номер заказа: <strong>{{ $order->order_number }}</strong></p>
        <p style="margin-top:16px;">Оплата не прошла или была отменена. Товары остаются зарезервированными недолго — попробуйте оформить заказ ещё раз.</p>
        <a href="{{ route('catalog.all') }}" class="btn" style="margin-top:32px;">Вернуться в каталог</a>
    </div>
@endsection
