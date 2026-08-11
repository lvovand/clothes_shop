<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;

/**
 * Автосоздание заявок включается отдельно у каждого перевозчика: интеграции
 * разной зрелости, и владельцу нужна возможность оставить один режим ручным.
 * Прежнее общее значение раздаём обоим.
 */
return new class extends Migration
{
    private const CARRIERS = ['yandex_auto_create', 'cdek_auto_create'];

    public function up(): void
    {
        $shared = SiteSetting::get('delivery_auto_create');

        foreach (self::CARRIERS as $key) {
            if (SiteSetting::get($key) === null) {
                SiteSetting::set($key, $shared ?? '1');
            }
        }
    }

    public function down(): void
    {
        $value = SiteSetting::get('yandex_auto_create');

        if ($value !== null) {
            SiteSetting::set('delivery_auto_create', $value);
        }
    }
};
