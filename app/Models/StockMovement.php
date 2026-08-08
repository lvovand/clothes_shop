<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка журнала складских движений. Пишется на каждое изменение остатка —
 * заказ, возврат при отмене, ручная корректировка в админке.
 */
class StockMovement extends Model
{
    public const REASONS = [
        'order' => 'Заказ',
        'return' => 'Возврат по отмене',
        'adjustment' => 'Корректировка',
        'import' => 'Импорт',
    ];

    protected $fillable = [
        'variant_id', 'warehouse_id', 'delta', 'reason',
        'order_id', 'order_number', 'user_id', 'comment',
    ];

    protected $casts = ['delta' => 'integer'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }
}
