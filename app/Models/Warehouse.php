<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = ['code', 'name', 'city', 'allows_pickup', 'sort_order', 'is_active'];

    protected $casts = [
        'allows_pickup' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(VariantStock::class);
    }

    /** Склады в порядке списания при доставке: первым тот, откуда отправляем обычно. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
