<?php

namespace App\Forms\Components;

use App\Models\SiteSetting;
use App\Services\YandexDelivery\YandexDeliveryClient;
use Filament\Forms\Components\Field;

/**
 * Выбор точки сдачи посылок Яндекс Доставки на карте.
 *
 * Список точек в городе — это тысячи адресов, поэтому выпадающего списка мало:
 * владельцу нужно видеть, что находится рядом с магазином. Точки подгружаются
 * маршрутом админки, карта — Яндекс.Карты с тем же ключом, что и на чекауте.
 */
class YandexDropoffPicker extends Field
{
    protected string $view = 'forms.components.yandex-dropoff-picker';

    /**
     * Подпись уже выбранной точки — чтобы её было видно сразу при открытии страницы,
     * ещё до того, как владелец нажмёт «Показать точки на карте».
     */
    public function getSelectedLabel(): ?string
    {
        $pointId = (string) $this->getState();

        if ($pointId === '') {
            return null;
        }

        $client = app(YandexDeliveryClient::class);

        if (! $client->isConfigured()) {
            return $pointId;
        }

        $city = (string) (SiteSetting::get('yandex_delivery_dropoff_city') ?: 'Москва');
        $geoId = $client->findGeoId($city);

        if (! $geoId) {
            return $pointId;
        }

        foreach ($client->pickupPoints($geoId, forDropoff: true) as $point) {
            if ($point['id'] === $pointId) {
                return ($point['type'] === 'warehouse' ? 'Склад: ' : 'ПВЗ: ').$point['name'].' — '.$point['address'];
            }
        }

        // Точка могла быть в другом городе или исчезнуть из списка Яндекса.
        return $pointId;
    }
}
