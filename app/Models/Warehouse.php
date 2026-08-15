<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'code', 'name', 'city', 'allows_pickup', 'sort_order', 'is_active',
        'cdek_sender_city_code', 'cdek_shipment_point', 'yandex_dropoff_city', 'yandex_dropoff_id',
    ];

    protected $casts = [
        'allows_pickup' => 'boolean',
        'is_active' => 'boolean',
        'cdek_sender_city_code' => 'integer',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(VariantStock::class);
    }

    /**
     * Может ли склад сам отправлять заказы этим перевозчиком. Точка отправления
     * у каждого своя: без неё перевозчик не посчитает цену и не примет заявку,
     * поэтому такой склад в отгрузке не участвует — товар с него довозится на
     * отгрузочный склад руками.
     */
    public function shipsVia(?string $provider): bool
    {
        return match ($provider) {
            'cdek' => (int) $this->cdek_sender_city_code > 0,
            'yandex' => (string) $this->yandex_dropoff_id !== '',
            // Самовывоз и свои способы доставки точки отправления не требуют.
            default => true,
        };
    }

    /** Склады в порядке списания при доставке: первым тот, откуда отправляем обычно. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }
}
