<?php

namespace Database\Seeders;

use App\Models\ShippingMethod;
use Illuminate\Database\Seeder;

class ShippingMethodSeeder extends Seeder
{
    public function run(): void
    {
        // Real config confirmed live on the old site during the 2026-08-01 audit.
        // cod_allowed mirrors the one hard business rule found there: cash-on-delivery
        // is only ever offered for pickup — this is now a first-class column instead
        // of a fragile enable_for_methods string match.
        $methods = [
            [
                'code' => 'pickup',
                'title' => 'Самовывоз',
                'is_enabled' => true,
                'cod_allowed' => true,
                'flat_cost' => 0,
                'free_from_amount' => null,
                'config' => ['address' => 'Москва, Мясницкая 10с1', 'provider' => 'none', 'kind' => 'pickup'],
                'sort_order' => 0,
            ],
            [
                'code' => 'cdek_pvz',
                'title' => 'CDEK: пункт выдачи',
                'is_enabled' => true,
                'cod_allowed' => false,
                'flat_cost' => null, // calculated live via CDEK API
                'free_from_amount' => 10000,
                'config' => ['tariff_code' => 136, 'provider' => 'cdek', 'kind' => 'pvz'],
                'sort_order' => 1,
            ],
            [
                'code' => 'cdek_door',
                'title' => 'CDEK: курьер до двери',
                'is_enabled' => true,
                'cod_allowed' => false,
                'flat_cost' => null,
                'free_from_amount' => 10000,
                'config' => ['tariff_code' => 137, 'provider' => 'cdek', 'kind' => 'door'],
                'sort_order' => 2,
            ],
            [
                'code' => 'courier_moscow',
                'title' => 'Курьер по Москве и ближайшему Подмосковью',
                'is_enabled' => true,
                'cod_allowed' => false,
                'flat_cost' => 500,
                'free_from_amount' => 10000,
                'config' => ['provider' => 'none', 'kind' => 'door'],
                'sort_order' => 3,
            ],
            [
                // Выключены по умолчанию — включает владелец в админке.
                'code' => 'yandex_pvz',
                'title' => 'Яндекс Доставка: пункт выдачи',
                'is_enabled' => false,
                'cod_allowed' => false,
                'flat_cost' => null,
                'free_from_amount' => 10000,
                'config' => ['provider' => 'yandex', 'kind' => 'pvz'],
                'sort_order' => 4,
            ],
            [
                'code' => 'yandex_door',
                'title' => 'Яндекс Доставка: курьер до двери',
                'is_enabled' => false,
                'cod_allowed' => false,
                'flat_cost' => null,
                'free_from_amount' => 10000,
                'config' => ['provider' => 'yandex', 'kind' => 'door'],
                'sort_order' => 5,
            ],
        ];

        foreach ($methods as $method) {
            ShippingMethod::updateOrCreate(['code' => $method['code']], $method);
        }
    }
}
