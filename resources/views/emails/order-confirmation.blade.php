<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #0c0c0c; max-width: 560px; margin: 0 auto;">
    <h1 style="text-transform: uppercase; font-size: 20px;">ROPA WORLD</h1>
    <p>Здравствуйте, {{ $order->customer_name }}!</p>
    <p>Ваш заказ <strong>{{ $order->order_number }}</strong> принят{{ $order->payment_status === 'paid' ? ' и оплачен' : '' }}.</p>

    <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
        <thead>
            <tr>
                <th style="text-align: left; border-bottom: 1px solid #ddd; padding: 6px 0;">Товар</th>
                <th style="text-align: center; border-bottom: 1px solid #ddd; padding: 6px 0;">Кол-во</th>
                <th style="text-align: right; border-bottom: 1px solid #ddd; padding: 6px 0;">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td style="padding: 6px 0; border-bottom: 1px solid #f0f0f0;">
                        {{ $item->product_title_snapshot }}
                        @if($item->variant_attrs_snapshot)
                            <br><span style="color: #666; font-size: 13px;">{{ $item->variant_attrs_snapshot }}</span>
                        @endif
                    </td>
                    <td style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; text-align: center;">{{ $item->qty }}</td>
                    <td style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; text-align: right;">{{ number_format($item->line_total, 0, ',', ' ') }} ₽</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="margin: 4px 0;">Товары: {{ number_format($order->subtotal, 0, ',', ' ') }} ₽</p>
    @if($order->discount_total > 0)
        <p style="margin: 4px 0;">Скидка: −{{ number_format($order->discount_total, 0, ',', ' ') }} ₽</p>
    @endif
    <p style="margin: 4px 0;">Доставка ({{ $order->shippingMethod->title ?? '—' }}): {{ $order->shipping_cost > 0 ? number_format($order->shipping_cost, 0, ',', ' ').' ₽' : 'бесплатно' }}</p>
    <p style="margin: 12px 0; font-size: 18px; font-weight: bold;">Итого: {{ number_format($order->total, 0, ',', ' ') }} ₽</p>

    <p style="margin: 4px 0;">Способ оплаты: {{ \App\Models\PaymentMethod::LABELS[$order->payment_method] ?? $order->payment_method }}</p>
    @if($order->shippingAddressText())
        <p style="margin: 4px 0;">Адрес доставки: {{ $order->shippingAddressText() }}</p>
    @endif
    @if($order->customer_phone)
        <p style="margin: 4px 0;">Телефон: {{ $order->customer_phone }}</p>
    @endif

    @if($order->comment)
        <p style="margin: 12px 0; color: #666; font-size: 13px;">Комментарий к заказу: {{ $order->comment }}</p>
    @endif

    <p style="margin-top: 24px; color: #666; font-size: 13px;">Мы сообщим вам, когда заказ будет передан в доставку. Если у вас есть вопросы — просто ответьте на это письмо.</p>
</body>
</html>
