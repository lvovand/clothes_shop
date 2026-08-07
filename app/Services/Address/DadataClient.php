<?php

namespace App\Services\Address;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Подсказки адреса от DaData — основной источник, когда задан ключ.
 *
 * Почему именно она: это российский адресный справочник (ФИАС), он ищет по
 * любой части названия, поэтому «вет» сразу даёт «пр-кт Ветеранов», и знает
 * корпуса с литерами. Бесплатный тариф — 10 000 подсказок в сутки, чего этому
 * магазину хватает с большим запасом. Ключ задаётся в админке
 * («Настройки → Доставка и оплата»), без него подсказки берутся из OSM.
 *
 * Секретный ключ DaData здесь не нужен: он требуется только «Стандартизации»,
 * а подсказкам достаточно обычного API-ключа.
 */
class DadataClient
{
    private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

    /** Типы населённых пунктов, куда возят заказы (всё прочее — территории и дороги). */
    private const SETTLEMENT_TYPES = [
        'посёлок', 'поселок', 'посёлок городского типа', 'поселок городского типа',
        'рабочий посёлок', 'рабочий поселок', 'село', 'деревня', 'станица', 'слобода',
        'хутор', 'аул', 'микрорайон', 'дачный посёлок', 'дачный поселок',
    ];

    public function isConfigured(): bool
    {
        return $this->token() !== '';
    }

    /**
     * @return array<int, array{label: string, hint: string, city: string}>
     */
    public function suggestCities(string $query): array
    {
        $suggestions = $this->request([
            'query' => $query,
            'count' => 10,
            'from_bound' => ['value' => 'city'],
            'to_bound' => ['value' => 'settlement'],
        ]);

        $items = [];

        foreach ($suggestions as $suggestion) {
            $data = $suggestion['data'] ?? [];
            $city = trim((string) ($data['city'] ?? ''));

            // Посёлки приходят в settlement, но там же лежат садоводства,
            // промзоны и участки автодорог — их в списке городов быть не должно.
            if ($city === '' && in_array($data['settlement_type_full'] ?? '', self::SETTLEMENT_TYPES, true)) {
                $city = trim((string) ($data['settlement'] ?? ''));
            }

            if ($city === '') {
                continue;
            }

            // Совпадение могло прийтись на район или регион («мос» → Рязань с
            // Московским районом): такие подсказки в списке городов бессмысленны.
            if (mb_stripos($city, trim($query)) === false) {
                continue;
            }

            $region = trim((string) ($data['region_with_type'] ?? ''));

            $items[mb_strtolower($city.'|'.$region)] = [
                'label' => $city,
                'hint' => $region !== '' && ! str_contains(mb_strtolower($region), mb_strtolower($city)) ? $region : '',
                'city' => $city,
            ];
        }

        return array_slice(array_values($items), 0, 8);
    }

    /**
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    public function suggestStreets(string $query, string $city): array
    {
        $suggestions = $this->request([
            'query' => $query,
            'count' => 15,
            'from_bound' => ['value' => 'street'],
            'to_bound' => ['value' => 'street'],
        ] + $this->within($city));

        $items = [];

        foreach ($suggestions as $suggestion) {
            $data = $suggestion['data'] ?? [];
            $street = trim((string) ($data['street_with_type'] ?? ''));

            // Станции метро DaData отдаёт как улицу с типом «метро» — адресом
            // доставки они не бывают.
            if ($street === '' || ($data['street_type_full'] ?? '') === 'метро') {
                continue;
            }

            // Улица внутри СНТ/промзоны: без названия этой территории адрес
            // неполный, поэтому дописываем её к самой улице.
            $settlement = trim((string) ($data['settlement_with_type'] ?? ''));
            $label = $settlement !== '' ? $settlement.', '.$street : $street;

            $items[mb_strtolower($label)] = [
                'label' => $label,
                'hint' => trim((string) ($data['city_district_with_type'] ?? '')),
                'street' => $label,
                'house' => '',
            ];
        }

        return array_slice(array_values($items), 0, 8);
    }

    /**
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    public function suggestHouses(string $street, string $house, string $city, bool $numbersOnly): array
    {
        $suggestions = $this->request([
            'query' => trim($street.' '.$house),
            'count' => 15,
            'from_bound' => ['value' => 'house'],
            'to_bound' => ['value' => 'house'],
        ] + $this->within($city));

        $items = [];

        foreach ($suggestions as $suggestion) {
            $data = $suggestion['data'] ?? [];
            $number = trim((string) ($data['house'] ?? ''));

            if ($number === '') {
                continue;
            }

            // Корпус/строение — отдельные поля: «78» + «к» + «2» → «78 к2».
            $block = trim((string) ($data['block'] ?? ''));
            if ($block !== '') {
                $type = trim((string) ($data['block_type'] ?? ''));
                // Короткий тип пишется слитно с номером («78 к2»), словесный —
                // через пробел («78 литера А»).
                $number .= ' '.$type.(mb_strlen($type) > 1 ? ' ' : '').$block;
            }

            $found = trim((string) ($data['street_with_type'] ?? ''));

            $items[mb_strtolower($found.'|'.$number)] = [
                'label' => $numbersOnly ? $number : trim($found.', '.$number, ', '),
                'hint' => $numbersOnly && $this->sameStreet($found, $street) ? '' : $found,
                'street' => $found !== '' ? $found : $street,
                'house' => $number,
            ];
        }

        return array_slice(array_values($items), 0, 8);
    }

    /**
     * Ограничение поиска городом. Именно `locations`, а не город в строке
     * запроса: иначе DaData ищет по всей фразе и подсказки по началу слова
     * перестают работать.
     *
     * @return array<string, mixed>
     */
    private function within(string $city): array
    {
        $city = trim($city);

        if ($city === '') {
            return [];
        }

        // Москва и Петербург — сами себе регионы, и по ключу `city` DaData их
        // тоже находит, поэтому отдельная ветка не нужна.
        return ['locations' => [['city' => $city], ['settlement' => $city]]];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function request(array $body): array
    {
        $query = trim((string) ($body['query'] ?? ''));

        if ($query === '' || ! $this->isConfigured()) {
            return [];
        }

        return Cache::remember(
            'dadata:'.md5(json_encode($body, JSON_UNESCAPED_UNICODE)),
            now()->addHours(6),
            function () use ($body) {
                try {
                    $response = Http::timeout(5)
                        ->withHeaders([
                            'Authorization' => 'Token '.$this->token(),
                            'Accept' => 'application/json',
                        ])
                        ->asJson()
                        ->post(self::ENDPOINT, $body);

                    if (! $response->successful()) {
                        // 403 — исчерпан дневной лимит или ключ отозван; выше по
                        // стеку это молча переключит подсказки на запасной источник.
                        Log::warning('DaData suggest failed', ['status' => $response->status()]);

                        return [];
                    }

                    return $response->json('suggestions') ?? [];
                } catch (\Throwable $e) {
                    Log::warning('DaData suggest failed', ['error' => $e->getMessage()]);

                    return [];
                }
            }
        );
    }

    private function sameStreet(string $a, string $b): bool
    {
        $key = fn (string $s) => preg_replace('/[^а-яёa-z0-9]/u', '', mb_strtolower($s));

        return $key($a) === $key($b);
    }

    private function token(): string
    {
        return trim((string) (SiteSetting::get('dadata_api_key') ?: config('services.dadata.key', '')));
    }
}
