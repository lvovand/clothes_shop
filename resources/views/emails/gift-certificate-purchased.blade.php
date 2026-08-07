<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #0c0c0c; max-width: 560px; margin: 0 auto;">
    <h1 style="text-transform: uppercase; font-size: 20px;">ROPA WORLD</h1>
    <p>Здравствуйте, {{ $certificate->recipient_name }}!</p>
    <p>
        @if($certificate->buyer_name)
            {{ $certificate->buyer_name }} подарил(а) вам подарочный сертификат ROPA WORLD.
        @else
            Вам подарили сертификат ROPA WORLD.
        @endif
    </p>

    @if($certificate->message)
        <blockquote style="border-left: 3px solid #0c0c0c; padding-left: 16px; font-style: italic;">{{ $certificate->message }}</blockquote>
    @endif

    <p style="font-size: 24px; font-weight: bold; letter-spacing: .05em;">{{ $certificate->code }}</p>
    <p>Номинал: {{ number_format($certificate->initial_amount, 0, ',', ' ') }} ₽</p>

    <p>Это ваш уникальный код сертификата — сохраните это письмо.</p>

    <p style="color: #666; font-size: 13px;">Сертификат действителен без ограничения по сроку. После оплаты сертификатом возврат производится новым сертификатом на ту же сумму.</p>
</body>
</html>
