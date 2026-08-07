@extends('layouts.app')

@section('content')
    <div class="container" style="padding:60px 0;text-align:center;max-width:600px;">
        <h1 style="text-transform:uppercase;font-size:24px;margin-bottom:16px;">Спасибо за покупку!</h1>
        <p style="color:var(--color-muted);">Как только оплата подтвердится, сертификат придёт письмом на {{ $certificate->recipient_email }}.</p>
        <a href="{{ route('catalog.all') }}" class="btn" style="margin-top:32px;">Продолжить покупки</a>
    </div>
@endsection
