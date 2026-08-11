<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Габариты посылки и тумблер автосоздания заявок были заведены под Яндекс
 * Доставку, но к перевозчику отношения не имеют — с появлением заявок СДЭК они
 * становятся общими. Значения переносим, чтобы владельцу не пришлось вводить их
 * заново.
 */
return new class extends Migration
{
    private const MAP = [
        'yandex_delivery_weight' => 'parcel_weight',
        'yandex_delivery_dx' => 'parcel_dx',
        'yandex_delivery_dy' => 'parcel_dy',
        'yandex_delivery_dz' => 'parcel_dz',
        'yandex_delivery_auto_create' => 'delivery_auto_create',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            $value = SiteSetting::get($old);

            if ($value !== null && SiteSetting::get($new) === null) {
                SiteSetting::set($new, $value);
            }
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            $value = SiteSetting::get($new);

            if ($value !== null) {
                SiteSetting::set($old, $value);
            }
        }
    }
};
