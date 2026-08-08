<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantStock extends Model
{
    protected $fillable = ['variant_id', 'warehouse_id', 'qty'];

    protected $casts = ['qty' => 'integer'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
