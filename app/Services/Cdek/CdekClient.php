<?php

namespace App\Services\Cdek;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CdekClient
{
    private const BASE_URL = 'https://api.cdek.ru/v2';

    public function __construct(
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
        private readonly int $senderCityCode,
    ) {
    }

    /**
     * Ключи СДЭК живут в настройках сайта, то есть их может не быть (чистая
     * установка, стёрли в админке). Без них клиент молча ничего не считает —
     * страница оформления обязана открываться и в этом случае.
     */
    public function isConfigured(): bool
    {
        return (string) $this->clientId !== '' && (string) $this->clientSecret !== '';
    }

    private function accessToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        return Cache::remember('cdek_access_token', now()->addMinutes(50), function () {
            $response = Http::asForm()->post(self::BASE_URL.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if (! $response->successful()) {
                Log::error('CDEK auth failed', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    private function client()
    {
        $token = $this->accessToken();

        return Http::withToken($token)->baseUrl(self::BASE_URL)->timeout(15);
    }

    /**
     * @param  array{code?: int, city?: string, postal_code?: string}  $toLocation
     * @param  array<int>  $tariffCodes
     * @return array<int, array{tariff_code: int, delivery_sum: float, period_min: int, period_max: int}>|null
     */
    public function calculateTariffs(array $toLocation, array $tariffCodes, float $weightGrams = 1000): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->client()->post('/calculator/tarifflist', [
            'type' => 1, // "интернет-магазин"
            'from_location' => ['code' => $this->senderCityCode],
            'to_location' => array_filter($toLocation),
            'packages' => [
                ['weight' => (int) $weightGrams],
            ],
        ]);

        if (! $response->successful()) {
            Log::warning('CDEK tariff calc failed', ['status' => $response->status(), 'body' => $response->body()]);

            return null;
        }

        $tariffs = collect($response->json('tariff_codes', []))
            ->whereIn('tariff_code', $tariffCodes)
            ->values()
            ->all();

        return $tariffs;
    }

    /**
     * Raw CDEK deliverypoints response — kept in CDEK's own shape (code/name/location/etc.)
     * because it's passed straight through as `officesRaw` to the official CDEK map widget,
     * which expects exactly that format.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getPickupPoints(int $cityCode): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->client()->get('/deliverypoints', [
            'city_code' => $cityCode,
            'type' => 'PVZ',
        ]);

        if (! $response->successful()) {
            Log::warning('CDEK PVZ list failed', ['status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    /**
     * Подсказки городов для поля «Выбрать город» на оформлении. Берём их у СДЭК,
     * а не у сторонних геосервисов: города и так должны быть теми, по которым
     * считается доставка, и ключи СДЭК уже настроены.
     *
     * `city` — то, что подставляется в поле (по нему же ищется код города при
     * расчёте), `label` — что показывается в списке, с регионом для различения
     * одноимённых городов.
     *
     * @return array<int, array{code: int, city: string, label: string}>
     */
    public function suggestCities(string $query, int $limit = 8): array
    {
        if (! $this->isConfigured() || mb_strlen($query) < 2) {
            return [];
        }

        $rows = Cache::remember(
            'cdek_city_suggest_'.md5(mb_strtolower($query).':'.$limit),
            now()->addDay(),
            function () use ($query) {
                // Именно `suggest/cities`, а не `location/cities`: второй ищет по
                // полному названию («Мос» не находит Москву), первый — как раз
                // подсказки по началу слова.
                $response = $this->client()->get('/location/suggest/cities', [
                    'name' => $query,
                    'country_code' => 'RU',
                ]);

                if (! $response->successful()) {
                    Log::warning('CDEK city suggest failed', ['status' => $response->status()]);

                    return [];
                }

                return $response->json();
            }
        );

        return collect($rows ?? [])
            ->map(function ($row) {
                // `suggest/cities` отдаёт full_name вида «Москва, Москва, Россия»;
                // в поле подставляем только сам город — по нему потом ищется код.
                $full = (string) ($row['full_name'] ?? '');
                $city = (string) ($row['city'] ?? trim(explode(',', $full)[0] ?? ''));

                return [
                    'code' => (int) ($row['code'] ?? 0),
                    'city' => $city,
                    'label' => $full !== '' ? $full : $city,
                ];
            })
            ->filter(fn ($row) => $row['city'] !== '')
            ->unique('label')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Look up a CDEK city code by name — needed before a tariff calculation or PVZ lookup.
     */
    public function findCityCode(string $cityName): ?int
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->client()->get('/location/cities', [
            'city' => $cityName,
            'size' => 1,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('0.code');
    }

    /**
     * Обратная сторона findCityCode: по коду вернуть человеческое название города.
     * Нужна админке, чтобы в поле «Город отправления» показывать «Москва, Россия»
     * вместо голого числа 44 (сохраняется по-прежнему код — его требует API).
     *
     * @return array{code: int, city: string, label: string}|null
     */
    public function cityByCode(int $code): ?array
    {
        if (! $this->isConfigured() || $code <= 0) {
            return null;
        }

        $row = Cache::remember('cdek_city_by_code_'.$code, now()->addDay(), function () use ($code) {
            $response = $this->client()->get('/location/cities', [
                'code' => $code,
                'size' => 1,
            ]);

            if (! $response->successful()) {
                Log::warning('CDEK city by code failed', ['status' => $response->status(), 'code' => $code]);

                return null;
            }

            return $response->json('0');
        });

        if (! is_array($row) || empty($row['city'])) {
            return null;
        }

        // `location/cities` (в отличие от `suggest/cities`) не отдаёт готовый full_name —
        // собираем его сами из города/региона/страны, отбрасывая повторы: у Москвы
        // город и регион называются одинаково.
        $parts = array_values(array_unique(array_filter([
            (string) $row['city'],
            (string) ($row['region'] ?? ''),
            (string) ($row['country'] ?? ''),
        ])));

        return [
            'code' => (int) $row['code'],
            'city' => (string) $row['city'],
            'label' => implode(', ', $parts),
        ];
    }
}
