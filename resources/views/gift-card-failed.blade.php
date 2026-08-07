@extends('layouts.app')

@section('content')
    <div class="container" style="padding:60px 0;text-align:center;max-width:600px;">
        <h1 style="text-transform:uppercase;font-size:24px;margin-bottom:16px;">Не получилось оплатить сертификат</h1>
        <p style="margin-top:16px;">Оплата не прошла или была отменена. Попробуйте оформить покупку ещё раз.</p>
        <a href="{{ route('gift-card.show') }}" class="btn" style="margin-top:32px;">Вернуться к покупке сертификата</a>
    </div>
@endsection
