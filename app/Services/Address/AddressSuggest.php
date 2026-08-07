<?php

namespace App\Services\Address;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Подсказки городов, улиц и домов для полей адреса на оформлении.
 *
 * Источник — Photon (автодополнение поверх данных OpenStreetMap): у нашего ключа
 * Яндекс.Карт геокодер и Геосаджест не работают (403 «Invalid api key» — это
 * отдельные платные сервисы), а Nominatim правилами запрещает автодополнение.
 * Запрос уходит только со стороны сервера, поэтому у посетителя страница
 * по-прежнему не тянет ни одного зарубежного ресурса.
 *
 * Адрес всё равно уезжает перевозчику строкой и распознаётся уже им, поэтому
 * подсказка здесь — помощь при вводе, а не источник истины: человек может
 * дописать адрес руками, и расчёт от этого не сломается.
 */
class AddressSuggest
{
    private const ENDPOINT = 'https://photon.komoot.io/api/';

    /**
     * Теги OSM, которыми Photon сам сузит выдачу до адресов. Без них ответ
     * приходит вперемешку с точками интереса и станциями метро («Тверская» —
     * это ещё и станция, площадь и книжный магазин), и отсеивать их пришлось бы
     * уже у себя, теряя половину лимита выдачи на мусор.
     */
    private const ADDRESS_TAGS = ['highway', 'building', 'place:house'];

    private const HOUSE_TAGS = ['building', 'place:house'];

    private const CITY_TAGS = ['place:city', 'place:town', 'place:village'];

    /**
     * Улицы города. Если в запросе есть номер дома («тверская 12»), человек уже
     * набрал адрес целиком — отвечаем сразу домами.
     *
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    public function suggest(string $query, ?string $city = null): array
    {
        $query = trim($query);
        $city = trim((string) $city);

        if (mb_strlen($query) < 3) {
            return [];
        }

        [$streetPart, $housePart] = $this->splitStreetAndHouse($query);

        if ($housePart !== '' && $streetPart !== '') {
            return $this->suggestHouses($streetPart, $housePart, $city);
        }

        return $this->remember('streets', [$query, $city], fn () => $this->fetchStreets($query, $city));
    }

    /**
     * Номера домов на уже выбранной улице — подсказка для поля «Дом».
     *
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    public function suggestHouses(string $street, string $house, ?string $city = null, bool $numbersOnly = false): array
    {
        $street = trim($street);
        $house = trim($house);
        $city = trim((string) $city);

        if ($street === '' || $house === '') {
            return [];
        }

        return $this->remember(
            'houses',
            [$street, $house, $city, $numbersOnly ? 'n' : 'f'],
            fn () => $this->fetchHouses($street, $house, $city, $numbersOnly)
        );
    }

    /**
     * @return array<int, array{label: string, hint: string, city: string}>
     */
    public function suggestCities(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->remember('cities', [$query], fn () => $this->fetchCities($query));
    }

    /**
     * @return array<int, array{label: string, hint: string, city: string}>
     */
    private function fetchCities(string $query): array
    {
        $features = $this->request($query, ['osm_tag' => self::CITY_TAGS, 'limit' => 15]);

        $items = [];

        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];

            // Страну Photon фильтровать не умеет — отсеиваем по ответу.
            if (($p['countrycode'] ?? '') !== 'RU') {
                continue;
            }

            $name = trim((string) ($p['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            // Регион в подписи нужен, чтобы различать одноимённые города.
            $region = trim((string) ($p['state'] ?? ''));

            $items[$this->key($name)] = [
                'label' => $name,
                'hint' => $region !== '' && $region !== $name ? $region : '',
                'city' => $name,
                'rank' => $this->matchRank($name, $query),
            ];
        }

        return $this->finish($items);
    }

    /**
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    private function fetchStreets(string $query, string $city): array
    {
        // Город в запросе обязателен: иначе «Ленина» приносит улицы со всей страны.
        $features = $this->request($this->withCity($query, $city), [
            'osm_tag' => self::ADDRESS_TAGS,
            'limit' => 25,
        ]);

        $items = [];

        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];

            if (! $this->inCity($p, $city)) {
                continue;
            }

            // У самой улицы название лежит в `name`, у дома на ней — в `street`.
            $street = trim((string) ($p['street'] ?? $p['name'] ?? ''));

            if ($street === '' || ! $this->looksLikeStreet($street)) {
                continue;
            }

            // Photon добирает выдачу соседними объектами: по «Кривоколенный» он
            // возвращает ещё и Мясницкую улицу. Оставляем только то, где набранное
            // действительно встречается в названии.
            if (! $this->matchesQuery($street, $query)) {
                continue;
            }

            // Дома схлопываются в свою улицу: на этом шаге номер человек ещё не
            // вводил, и список из двадцати строений вместо пяти улиц — это шум.
            $items[$this->key($street)] = [
                'label' => $street,
                'hint' => $this->districtOf($p, $city),
                'street' => $street,
                'house' => '',
                'rank' => $this->matchRank($street, $query),
            ];
        }

        return $this->finish($items);
    }

    /**
     * @return array<int, array{label: string, hint: string, street: string, house: string}>
     */
    private function fetchHouses(string $street, string $house, string $city, bool $numbersOnly): array
    {
        // Номер отделяется запятой («Москва, Тверская улица, 12»): без неё Photon
        // считает его частью названия и первой же выдаёт 1-ю Тверскую-Ямскую.
        // Улицы (highway) из тегов убраны — на этом шаге нужны только дома.
        $features = $this->request($this->withCity($street.', '.$house, $city), [
            'osm_tag' => self::HOUSE_TAGS,
            'limit' => 30,
        ]);

        $items = [];

        foreach ($features as $feature) {
            $p = $feature['properties'] ?? [];

            if (! $this->inCity($p, $city)) {
                continue;
            }

            $found = trim((string) ($p['street'] ?? ''));
            $number = trim((string) ($p['housenumber'] ?? ''));

            if ($found === '' || $number === '') {
                continue;
            }

            // По запросу «Тверская 12» Photon охотно приносит дома с 1-й по 4-ю
            // Тверскую-Ямскую. Совсем отбрасывать их нельзя (человек мог набрать
            // улицу неточно), но своя улица должна быть выше любой похожей —
            // отсюда двузначный ранг: десятки за улицу, единицы за номер дома.
            $sameStreet = $this->streetKey($found) === $this->streetKey($street);

            $items[$this->key($found.' '.$number)] = [
                // В подсказке к полю «Дом» улица уже выбрана и видна рядом —
                // повторять её в каждой строке значит прятать сам номер.
                'label' => $numbersOnly ? $number : $found.', '.$number,
                // Улица в уточнении нужна только там, где её нет в самой строке
                // и она отличается от набранной.
                'hint' => $numbersOnly
                    ? ($sameStreet ? '' : $found)
                    : $this->districtOf($p, $city),
                'street' => $found,
                'house' => $number,
                'rank' => ($sameStreet ? 10 : 0) + $this->houseRank($number, $house),
            ];
        }

        return $this->finish($items);
    }

    /**
     * Насколько подсказка похожа на введённое: точное совпадение → начинается с
     * запроса → просто содержит его. Photon сортирует по своей «важности»
     * объекта, из-за чего проспект районного значения оказывался выше улицы,
     * название которой человек набрал целиком.
     */
    private function matchRank(string $value, string $query): int
    {
        $value = $this->normalize($value);
        $query = $this->normalize($query);

        if ($value === $query) {
            return 3;
        }

        if ($query !== '' && str_starts_with($value, $query)) {
            return 2;
        }

        return 1;
    }

    /** «12» важнее «12 с9», «12 с9» важнее «120», а «25/12» — в самом конце. */
    private function houseRank(string $number, string $query): int
    {
        if ($this->key($number) === $this->key($query)) {
            return 4;
        }

        $numberDigits = $this->leadingNumber($number);
        $queryDigits = $this->leadingNumber($query);

        if ($queryDigits === '') {
            return 1;
        }

        // Тот же дом, другой корпус или строение.
        if ($numberDigits === $queryDigits) {
            return 3;
        }

        return str_starts_with($numberDigits, $queryDigits) ? 2 : 1;
    }

    private function leadingNumber(string $value): string
    {
        return preg_match('/^\s*(\d+)/u', $value, $m) ? $m[1] : '';
    }

    /** Отсортировать по совпадению, снять служебное поле и обрезать до восьми. */
    private function finish(array $items): array
    {
        uasort($items, function ($a, $b) {
            return [$b['rank'], mb_strlen($a['label'])] <=> [$a['rank'], mb_strlen($b['label'])];
        });

        return array_map(
            fn ($item) => \Illuminate\Support\Arr::except($item, 'rank'),
            array_slice(array_values($items), 0, 8)
        );
    }

    /** Набранное встречается в названии — с точностью до родовых слов и дефисов. */
    private function matchesQuery(string $value, string $query): bool
    {
        return str_contains($this->key($value), $this->key($query))
            || str_contains($this->streetKey($value), $this->streetKey($query));
    }

    /** Улицей считаем всё, что не выглядит номером дома или единичным словом-мусором. */
    private function looksLikeStreet(string $value): bool
    {
        return ! preg_match('/^\d/u', $value);
    }

    private function withCity(string $query, string $city): string
    {
        return $city !== '' ? $city.', '.$query : $query;
    }

    private function inCity(array $properties, string $city): bool
    {
        if ($city === '') {
            return true;
        }

        // У московских объектов город бывает пустым, а название лежит в state —
        // проверяем все три поля, и только явное несовпадение считаем чужим.
        foreach (['city', 'county', 'state'] as $field) {
            $found = trim((string) ($properties[$field] ?? ''));

            if ($found !== '' && $this->sameCity($city, $found)) {
                return true;
            }
        }

        return trim((string) ($properties['city'] ?? '')) === ''
            && trim((string) ($properties['state'] ?? '')) === '';
    }

    /** Район в подписи различает одноимённые улицы в разных концах города. */
    private function districtOf(array $properties, string $city): string
    {
        $district = trim((string) ($properties['district'] ?? ''));

        if ($district === '' || ($city !== '' && $this->sameCity($city, $district))) {
            return '';
        }

        return $district;
    }

    private function remember(string $kind, array $parts, \Closure $resolve): array
    {
        return Cache::remember(
            'address-suggest:'.$kind.':'.md5(implode('|', array_map(mb_strtolower(...), $parts))),
            now()->addHours(6),
            $resolve
        );
    }

    /**
     * Запрос к Photon. Ошибки не поднимаем: подсказки — необязательная помощь,
     * при недоступности источника поле просто остаётся с ручным вводом.
     *
     * @return array<int, array<string, mixed>>
     */
    private function request(string $query, array $params = []): array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'ropaworld.ru checkout address lookup'])
                ->get(self::ENDPOINT.'?'.$this->buildQuery(array_merge([
                    'q' => $query,
                    // ru не поддерживается (только default/de/en/fr), но у российских
                    // объектов OSM названия и так русские.
                    'lang' => 'default',
                ], $params)));

            if (! $response->successful()) {
                return [];
            }

            return $response->json('features') ?? [];
        } catch (\Throwable $e) {
            Log::warning('Address suggest failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Строка запроса своими руками: несколько osm_tag Photon ждёт повторяющимся
     * ключом (`osm_tag=highway&osm_tag=building`), а http_build_query, который
     * применяет клиент Laravel, пронумеровал бы их (`osm_tag[0]=…`) — тогда
     * фильтр не срабатывает и ответ приходит пустым.
     */
    private function buildQuery(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            foreach ((array) $value as $item) {
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Разделить «тверская 12с3» на улицу и номер дома. Номером считаем только
     * хвост строки: «1-я Тверская-Ямская» начинается с цифры, но это улица.
     *
     * @return array{0: string, 1: string}
     */
    private function splitStreetAndHouse(string $query): array
    {
        if (preg_match('/^(.*?[^\d\s,][^,]*?)[\s,]+(\d[\w\/\-\s]*)$/u', $query, $m)) {
            return [trim($m[1]), trim($m[2])];
        }

        return [$query, ''];
    }

    /** «Санкт-Петербург» и «Saint Petersburg» — один город, сравниваем мягко. */
    private function sameCity(string $a, string $b): bool
    {
        return $this->key($a) === $this->key($b)
            || str_contains($this->key($a), $this->key($b))
            || str_contains($this->key($b), $this->key($a));
    }

    /**
     * Ключ улицы без родового слова: в поле человек пишет «Тверская», а в OSM
     * улица называется «Тверская улица» — это одно и то же.
     */
    private function streetKey(string $value): string
    {
        $value = preg_replace(
            '/\b(улица|ул|переулок|пер|проспект|пр-кт|просп|шоссе|ш|бульвар|б-р|набережная|наб|площадь|пл|проезд|тупик|аллея|линия)\b/u',
            ' ',
            mb_strtolower($value)
        );

        return $this->key((string) $value);
    }

    /** Ключ для сравнения и дедупликации: только буквы и цифры в нижнем регистре. */
    private function key(string $value): string
    {
        return (string) preg_replace('/[^а-яёa-z0-9]/u', '', mb_strtolower($value));
    }

    /** То же, но с сохранением границ слов — для сравнения с началом запроса. */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return (string) preg_replace('/\s+/u', ' ', preg_replace('/[^а-яёa-z0-9\s]/u', '', $value));
    }
}
