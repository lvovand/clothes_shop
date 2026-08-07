<?php

namespace App\Services\Address;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Полный список улиц города из OpenStreetMap, по которому и ищутся подсказки.
 *
 * Зачем отдельный индекс, а не поиск в чужом сервисе на каждое нажатие: у
 * Photon (единственного бесплатного автодополнения по OSM) поиск идёт по началу
 * названия, поэтому «вет» не находит «проспект Ветеранов» — родовое слово стоит
 * первым. Платные адресные сервисы Яндекса тут не годятся: ключ магазина
 * (`yandex_map_api_key`) работает только с JavaScript API карт, а Геокодер и
 * Геосаджест отвечают ему 403 «Invalid api key» — это отдельные подписки.
 *
 * Список улиц одного города — несколько тысяч названий, он редко меняется и
 * целиком помещается в кеш, поэтому мы забираем его разом через Overpass и
 * дальше ищем у себя: мгновенно, по любой части названия и без внешних запросов.
 */
class StreetIndex
{
    /** Официальный overpass-api.de регулярно отвечает 429/504 — берём зеркало. */
    private const ENDPOINT = 'https://overpass.kumi.systems/api/interpreter';

    private const TTL_DAYS = 30;

    /** @return array<int, string>|null null — индекса ещё нет */
    public function get(string $city): ?array
    {
        return Cache::get($this->key($city));
    }

    /**
     * Запустить сборку отдельным процессом и сразу вернуться.
     *
     * Сборка идёт около минуты, и внутри запроса её делать нельзя даже после
     * отдачи ответа: PHP-FPM держит соединение до конца скрипта, поэтому первый
     * же ввод адреса в новом городе отвечал 57 секунд. Обработчика очередей на
     * сервере нет (`queue:work` не запущен), так что задача уходит в фоновый
     * процесс artisan — он переживает завершение запроса.
     *
     * @return bool false, если запустить фоном не удалось и звать build() придётся самим
     */
    public function scheduleBuild(string $city): bool
    {
        // Пока человек печатает город, запросов приходит несколько — метка не даёт
        // запустить сборку одного и того же города по разу на каждое нажатие.
        if (! Cache::add($this->queuedKey($city), true, now()->addMinutes(10))) {
            return true;
        }

        if (! function_exists('exec') || in_array('exec', explode(',', (string) ini_get('disable_functions')), true)) {
            return false;
        }

        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;

        @exec(sprintf(
            'nohup %s %s app:build-street-index %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            escapeshellarg($city)
        ));

        return true;
    }

    /**
     * Найти улицы по части названия. Совпадение с начала названия важнее
     * совпадения с начала слова внутри, а оно — важнее совпадения где угодно
     * («Тверская» → «Тверская улица», а не «площадь Тверская Застава»).
     *
     * @param  array<int, string>  $streets
     * @return array<int, string>
     */
    public function search(array $streets, string $query, int $limit = 8): array
    {
        $needle = $this->normalize($query);

        if ($needle === '') {
            return [];
        }

        $found = [];

        foreach ($streets as $street) {
            $value = $this->normalize($street);
            $at = mb_strpos($value, $needle);

            if ($at === false) {
                continue;
            }

            $rank = match (true) {
                $at === 0 => 3,
                mb_substr($value, $at - 1, 1) === ' ' => 2,
                default => 1,
            };

            $found[] = ['street' => $street, 'rank' => $rank, 'length' => mb_strlen($street)];
        }

        usort($found, fn ($a, $b) => [$b['rank'], $a['length']] <=> [$a['rank'], $b['length']]);

        return array_column(array_slice($found, 0, $limit), 'street');
    }

    /**
     * Построить индекс. Занимает около десяти секунд, поэтому вызывается не в
     * запросе покупателя, а после ответа (см. AddressSuggest).
     *
     * @return array<int, string>
     */
    /**
     * @param  array{lat: float, lon: float, extent: array<int, float>|null}|null  $box
     *                                                                                    Координаты и рамка города — нужны, когда у города нет
     *                                                                                    административной границы в OSM (так, например, у Оренбурга).
     * @return array<int, string>
     */
    public function build(string $city, ?array $box = null): array
    {
        $lock = Cache::lock('street-index-build:'.md5(mb_strtolower($city)), 300);

        if (! $lock->get()) {
            // Кто-то уже строит этот же город — второй запрос ничего не добавит.
            return [];
        }

        try {
            // Сборка занимает до минуты и идёт уже после ответа покупателю:
            // без этого её обрывал лимит времени PHP-FPM, и индекс не появлялся.
            @set_time_limit(180);
            ignore_user_abort(true);

            // Сначала по административной границе города — она даёт ровно его
            // улицы. Если такой границы в OSM нет (у Оренбурга, например, её не
            // оказалось), берём улицы в рамке города по его координатам.
            $streets = $this->fetch($this->areaQuery($city));

            if ($streets === [] && $box !== null) {
                $streets = $this->fetch($this->boxQuery($box));
            }

            if ($streets === []) {
                Log::warning('Street index build returned nothing', ['city' => $city, 'box' => $box]);

                return [];
            }

            Cache::put($this->key($city), $streets, now()->addDays(self::TTL_DAYS));

            return $streets;
        } catch (\Throwable $e) {
            Log::warning('Street index build failed', ['city' => $city, 'error' => $e->getMessage()]);

            return [];
        } finally {
            $lock->release();
        }
    }

    /** CSV вместо JSON: те же названия весят 1 МБ вместо 10, и разбирать проще. */
    private function areaQuery(string $city): string
    {
        return '[out:csv(name;false)][timeout:120];'
            .'area["name"="'.str_replace('"', '', $city).'"]["admin_level"~"^(4|6|8)$"]->.a;'
            .'way(area.a)["highway"]["name"];out tags;';
    }

    /** @param array{lat: float, lon: float, extent: array<int, float>|null} $box */
    private function boxQuery(array $box): string
    {
        if (($box['extent'] ?? null) !== null && count($box['extent']) === 4) {
            // Photon отдаёт рамку как [minLon, maxLat, maxLon, minLat],
            // Overpass ждёт (south, west, north, east).
            [$minLon, $maxLat, $maxLon, $minLat] = $box['extent'];
            $bounds = implode(',', [$minLat, $minLon, $maxLat, $maxLon]);
        } else {
            // Рамки нет — 15 км вокруг центра: столько хватает на город,
            // не притаскивая улицы соседних населённых пунктов.
            $bounds = null;
        }

        $filter = $bounds !== null
            ? '(' .$bounds.')'
            : '(around:15000,'.$box['lat'].','.$box['lon'].')';

        return '[out:csv(name;false)][timeout:120];'
            .'way["highway"]["name"]'.$filter.';out tags;';
    }

    /** @return array<int, string> */
    private function fetch(string $query): array
    {
        $response = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'ropaworld.ru street index'])
            ->asForm()
            ->post(self::ENDPOINT, ['data' => $query]);

        if (! $response->successful()) {
            Log::warning('Street index request failed', ['status' => $response->status()]);

            return [];
        }

        return collect(preg_split('/\R/u', $response->body()))
            ->map(fn ($line) => trim($line))
            ->filter(fn ($line) => $line !== '' && ! preg_match('/^\d+$/u', $line))
            ->unique()
            ->values()
            ->all();
    }

    private function queuedKey(string $city): string
    {
        return 'street-index-queued:'.md5(mb_strtolower(trim($city)));
    }

    private function key(string $city): string
    {
        return 'street-index:'.md5(mb_strtolower(trim($city)));
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^а-яёa-z0-9\s-]/u', '', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
