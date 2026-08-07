<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    /**
     * Как способ оплаты заказа называется в админке и в уведомлениях. Ключи здесь —
     * значения orders.payment_method, а не только записи этой таблицы: 'cod' платёжным
     * шлюзом не является, а 'card' остался в заказах, оформленных до того, как
     * эквайер стал выбираемым.
     */
    public const LABELS = [
        'cod' => 'Наличными при получении',
        'card' => 'Картой онлайн',
        'tbank' => 'Картой онлайн (Т-Банк)',
        'yandex_pay' => 'Картой онлайн (Яндекс Пэй)',
        'yandex_split' => 'Частями (Яндекс Сплит)',
    ];

    protected $fillable = ['key', 'name', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
