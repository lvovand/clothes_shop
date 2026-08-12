<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /**
     * Подписи статусов. Живут в модели, а не в админке: их показывает и Filament,
     * и мини-приложение бота — расхождение подписей в двух местах читалось бы как
     * два разных набора статусов.
     */
    public const STATUS_LABELS = [
        'new' => 'Новый', 'awaiting_payment' => 'Ожидает оплаты', 'paid' => 'Оплачен',
        'shipped' => 'Отправлен', 'completed' => 'Выполнен', 'cancelled' => 'Отменён',
    ];

    public const PAYMENT_STATUS_LABELS = [
        'pending' => 'Ожидает', 'paid' => 'Оплачен', 'failed' => 'Ошибка', 'refunded' => 'Возврат',
    ];

    protected $fillable = [
        'order_number', 'status', 'customer_name', 'customer_phone', 'customer_email',
        'shipping_method_id', 'shipping_address', 'shipping_cost',
        'payment_method', 'payment_status', 'subtotal', 'discount_total', 'coupon_code',
        'gift_certificate_code', 'gift_certificate_used', 'total', 'comment',
        'stock_returned_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'stock_returned_at' => 'datetime',
    ];

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUS_LABELS[$this->payment_status] ?? (string) $this->payment_status;
    }

    /**
     * Адрес одной строкой для показа человеку (уведомления, письма).
     *
     * Само поле хранится массивом (город, адрес, код и адрес ПВЗ), поэтому
     * подставлять его в текст напрямую нельзя — приведение массива к строке
     * роняет вызывающий код.
     */
    public function shippingAddressText(): ?string
    {
        $parts = array_filter([
            $this->shipping_address['city'] ?? null,
            $this->shipping_address['address'] ?? null,
            $this->shipping_address['pvz_address'] ?? null,
        ]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
