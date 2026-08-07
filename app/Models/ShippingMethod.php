<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingMethod extends Model
{
    protected $fillable = [
        'code', 'title', 'is_enabled', 'cod_allowed',
        'flat_cost', 'free_from_amount', 'config', 'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'cod_allowed' => 'boolean',
        'config' => 'array',
    ];

    /** Кто везёт: none (свои силы), cdek, yandex. */
    public function provider(): string
    {
        return (string) ($this->config['provider'] ?? 'none');
    }

    /** Что за способ: pickup (самовывоз), pvz (пункт выдачи), door (курьер). */
    public function kind(): string
    {
        return (string) ($this->config['kind'] ?? 'door');
    }

    /** Нужно выбрать пункт выдачи на карте/в списке. */
    public function needsPickupPoint(): bool
    {
        return $this->kind() === 'pvz';
    }

    /** Нужен адрес получателя. */
    public function needsAddress(): bool
    {
        return $this->kind() === 'door';
    }
}
