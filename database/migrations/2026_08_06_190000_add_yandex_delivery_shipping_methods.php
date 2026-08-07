<?php

use App\Models\ShippingMethod;
use Illuminate\Database\Migrations\Migration;

/**
 * Способы доставки Яндекса + признак провайдера у существующих способов.
 *
 * Провайдер лежит в `config`, а не отдельной колонкой, чтобы не менять схему из-за
 * двух строк: раньше поведение способа определялось сравнением с кодом
 * (`in_array($method->code, ['cdek_pvz', ...])`), а такие проверки ломаются на каждом
 * новом способе. Теперь код способа — только идентификатор.
 */
return new class extends Migration
{
    public function up(): void
    {
        $providers = [
            'pickup' => ['provider' => 'none', 'kind' => 'pickup'],
            'courier_moscow' => ['provider' => 'none', 'kind' => 'door'],
            'cdek_pvz' => ['provider' => 'cdek', 'kind' => 'pvz'],
            'cdek_door' => ['provider' => 'cdek', 'kind' => 'door'],
        ];

        foreach ($providers as $code => $extra) {
            $method = ShippingMethod::where('code', $code)->first();

            if ($method) {
                $method->update(['config' => array_merge($method->config ?? [], $extra)]);
            }
        }

        $maxSort = (int) ShippingMethod::max('sort_order');

        // Выключены по умолчанию: включает владелец в админке, когда решит
        // переключиться с СДЭК на Яндекс.
        $new = [
            [
                'code' => 'yandex_pvz',
                'title' => 'Яндекс Доставка: пункт выдачи',
                'is_enabled' => false,
                'cod_allowed' => false,
                'flat_cost' => null,
                'free_from_amount' => 10000,
                'config' => ['provider' => 'yandex', 'kind' => 'pvz'],
                'sort_order' => $maxSort + 1,
            ],
            [
                'code' => 'yandex_door',
                'title' => 'Яндекс Доставка: курьер до двери',
                'is_enabled' => false,
                'cod_allowed' => false,
                'flat_cost' => null,
                'free_from_amount' => 10000,
                'config' => ['provider' => 'yandex', 'kind' => 'door'],
                'sort_order' => $maxSort + 2,
            ],
        ];

        foreach ($new as $method) {
            ShippingMethod::firstOrCreate(['code' => $method['code']], $method);
        }
    }

    public function down(): void
    {
        ShippingMethod::whereIn('code', ['yandex_pvz', 'yandex_door'])->delete();
    }
};
