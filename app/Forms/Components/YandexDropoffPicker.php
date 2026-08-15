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

    /** Поле с городом рядом в той же форме — по нему подбираются точки. */
    protected string $cityField = 'yandex_delivery_dropoff_city';

    /** Поле с токеном, если он редактируется в этой же форме. */
    protected ?string $tokenField = 'yandex_delivery_token';

    /**
     * Точка сдачи выбирается и в настройках сайта, и в карточке склада — поля там
     * называются по-разному, поэтому имена соседних полей задаются снаружи.
     */
    public function cityField(string $name, ?string $tokenField = null): static
    {
        $this->cityField = $name;
        $this->tokenField = $tokenField;

        return $this;
    }

    /** Путь состояния соседнего поля: свой statePath без последнего сегмента. */
    private function siblingStatePath(?string $field): ?string
    {
        if (! $field) {
            return null;
        }

        $parts = explode('.', $this->getStatePath());
        array_pop($parts);
        $parts[] = $field;

        return implode('.', $parts);
    }

    public function getCityStatePath(): string
    {
        return (string) $this->siblingStatePath($this->cityField);
    }

    public function getTokenStatePath(): ?string
    {
        return $this->siblingStatePath($this->tokenField);
    }

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

        // Город берём из соседнего поля формы: у склада он свой, а не общий.
        $city = (string) ($this->evaluate(fn (callable $get) => $get($this->cityField))
            ?: SiteSetting::get('yandex_delivery_dropoff_city')
            ?: 'Москва');
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
