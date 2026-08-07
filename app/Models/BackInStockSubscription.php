<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackInStockSubscription extends Model
{
    protected $fillable = ['variant_id', 'email', 'phone', 'notified_at'];

    protected $casts = [
        'notified_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
