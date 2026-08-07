<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'min_subtotal',
        'starts_at', 'expires_at', 'usage_limit', 'used_count', 'is_active',
    ];

    protected $casts = [
        'value' => 'float',
        'min_subtotal' => 'float',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    /** Код ищем без учёта регистра и пробелов по краям — покупатель вводит его руками. */
    public static function findByCode(string $code): ?self
    {
        $code = trim($code);

        return $code === '' ? null : static::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();
    }

    /** Причина, по которой купон нельзя применить, или null — если можно. */
    public function rejectionReason(float $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'Промокод не найден';
        }
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Промокод ещё не действует';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Срок действия промокода истёк';
        }
        if ($this->usage_limit !== null && $this->used_count >= $this->usage_limit) {
            return 'Промокод больше не действует';
        }
        if ($this->min_subtotal !== null && $subtotal < $this->min_subtotal) {
            return 'Промокод действует от '.number_format($this->min_subtotal, 0, ',', ' ').' ₽';
        }

        return null;
    }

    /** Скидка в рублях от суммы товаров; больше самой суммы не бывает. */
    public function discountFor(float $subtotal): float
    {
        $discount = $this->type === 'percent'
            ? $subtotal * $this->value / 100
            : $this->value;

        return round(min($discount, $subtotal), 2);
    }
}
