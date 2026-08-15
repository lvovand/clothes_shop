<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shipment extends Model
{
    protected $fillable = [
        'order_id', 'warehouse_id', 'provider', 'tracking_number', 'pvz_code', 'pvz_address', 'status', 'raw_response',
    ];

    protected $casts = [
        'raw_response' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Откуда уехало это отправление: у заказа с двух складов их два. */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
