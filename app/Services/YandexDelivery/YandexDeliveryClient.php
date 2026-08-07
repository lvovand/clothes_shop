<?php

namespace App\Services\YandexDelivery;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Яндекс Доставка (логистическая платформа), REST API b2b/platform.
 *
 * Схема, которую поддерживает наш кабинет (проверено живыми запросами):
 * посылка едет ИЗ точки сдачи Яндекса (склад или ПВЗ, где магазин сдаёт заказы —
 * `source.platform_station`) В пункт выдачи (`destination.platform_station`) либо
 * по адресу курьером (`destination.custom_location`). Забор курьером с адреса
 * магазина этим кабинетом не поддерживается — API отвечает
 * «Route point is not a station».
 */
class YandexDeliveryClient
{
    private const BASE_URL = 'https://b2b.taxi.yandex.net/api/b2b/platform';

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $dropoffPointId,
        private readonly ?string $senderPhone,
        private readonly ?string $senderName,
    ) {
    }

    /**
     * Токена может не быть (чистая установка, стёрли в админке) — тогда клиент молча
     * ничего не считает, а страница оформления обязана открываться и в этом случае.
     */
    public function isConfigured(): bool
    {
        return (string) $this->token !== '';
    }

    /** Для расчётов нужна ещё и точка, куда магазин сдаёт посылки. */
    public function canCalculate(): bool
    {
        return $this->isConfigured() && (string) $this->dropoffPointId !== '';
    }

    private function client()
    {
        return Http::withToken($this->token)
            ->baseUrl(self::BASE_URL)
            ->withHeaders(['Accept-Language' => 'ru'])
            ->timeout(20);
    }

    /**
     * Варианты населённых пунктов по введённой строке — для подсказок города
     * и для получения geo_id, по которому запрашиваются пункты выдачи.
     *
     * @return array<int, array{geo_id: int, address: string}>
     */
    public function detectLocation(string $query): array
    {
        if (! $this->isConfigured() || mb_strlen($query) < 2) {
            return [];
        }

        return Cache::remember('yd_location_'.md5(mb_strtolower($query)), now()->addDay(), function () use ($query) {
            $response = $this->client()->post('/location/detect', ['location' => $query]);

            if (! $response->successful()) {
                $this->logFailure('location/detect', $response);

                return [];
            }

            return collect($response->json('variants', []))
                ->map(fn ($variant) => [
                    'geo_id' => (int) ($variant['geo_id'] ?? 0),
                    'address' => (string) ($variant['address'] ?? ''),
                ])
                ->filter(fn ($variant) => $variant['geo_id'] > 0)
                ->values()
                ->all();
        });
    }

    /** geo_id первого (самого точного) варианта для строки города. */
    public function findGeoId(string $city): ?int
    {
        return $this->detectLocation($city)[0]['geo_id'] ?? null;
    }

    /**
     * Пункты выдачи в населённом пункте. `$forDropoff` — точки, куда магазин может
     * сдавать посылки (нужно для выбора точки сдачи в админке).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pickupPoints(int $geoId, bool $forDropoff = false): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        $key = 'yd_points_'.$geoId.($forDropoff ? '_drop' : '');

        return Cache::remember($key, now()->addHours(6), function () use ($geoId, $forDropoff) {
            $payload = ['geo_id' => $geoId];
            if ($forDropoff) {
                $payload['available_for_dropoff'] = true;
            }

            $response = $this->client()->post('/pickup-points/list', $payload);

            if (! $response->successful()) {
                $this->logFailure('pickup-points/list', $response);

                return [];
            }

            return collect($response->json('points', []))
                ->map(fn ($point) => [
                    'id' => (string) ($point['id'] ?? ''),
                    'name' => (string) ($point['name'] ?? ''),
                    'type' => (string) ($point['type'] ?? ''),
                    'address' => (string) ($point['address']['full_address'] ?? ''),
                    'comment' => (string) ($point['address']['comment'] ?? ''),
                    'latitude' => (float) ($point['position']['latitude'] ?? 0),
                    'longitude' => (float) ($point['position']['longitude'] ?? 0),
                    'schedule' => $point['schedule'] ?? null,
                    'payment_methods' => $point['payment_methods'] ?? [],
                ])
                ->filter(fn ($point) => $point['id'] !== '')
                ->values()
                ->all();
        });
    }

    public function findPoint(int $geoId, string $pointId): ?array
    {
        foreach ($this->pickupPoints($geoId) as $point) {
            if ($point['id'] === $pointId) {
                return $point;
            }
        }

        return null;
    }

    /**
     * Стоимость доставки (минимальная из предложенных вариантов) и срок.
     *
     * @param  array<int, array{name: string, article: string, qty: int, price: float}>  $items
     * @param  array{point_id?: string, latitude?: float, longitude?: float, address?: string}  $to
     * @return array{cost: float, offer_id: string, delivery_min: ?string, delivery_max: ?string}|null
     */
    public function quote(array $items, array $to, string $requestId, array $dims = []): ?array
    {
        $offers = $this->offers($items, $to, $requestId, $dims);

        if (! $offers) {
            return null;
        }

        $best = null;
        foreach ($offers as $offer) {
            $cost = $this->parsePrice($offer['offer_details']['pricing_total'] ?? null);
            if ($cost === null) {
                continue;
            }
            if ($best === null || $cost < $best['cost']) {
                $best = [
                    'cost' => $cost,
                    'offer_id' => (string) ($offer['offer_id'] ?? ''),
                    'delivery_min' => $offer['offer_details']['delivery_interval']['min'] ?? null,
                    'delivery_max' => $offer['offer_details']['delivery_interval']['max'] ?? null,
                ];
            }
        }

        return $best;
    }

    /**
     * Сырые варианты доставки. Один вызов не создаёт заявку — только предложения,
     * которые живут ~15 минут (`expires_at`).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function offers(array $items, array $to, string $requestId, array $dims = []): ?array
    {
        if (! $this->canCalculate()) {
            return null;
        }

        $destination = $this->destination($to);

        if (! $destination) {
            return null;
        }

        $response = $this->client()->post('/offers/create', [
            'billing_info' => ['payment_method' => 'already_paid'],
            'info' => ['operator_request_id' => $requestId],
            'sender_info' => [
                'phone' => (string) $this->normalizePhone($this->senderPhone),
                'first_name' => (string) ($this->senderName ?: 'ROPA WORLD'),
                'last_name' => '',
            ],
            'source' => ['platform_station' => ['platform_id' => $this->dropoffPointId]],
            'destination' => $destination,
            'items' => $this->items($items, $requestId, $dims),
            'places' => [[
                'physical_dims' => $this->placeDims($items, $dims),
                'barcode' => $requestId.'-1',
            ]],
            'recipient_info' => [
                'first_name' => (string) ($to['first_name'] ?? 'Покупатель'),
                'last_name' => (string) ($to['last_name'] ?? ''),
                // На расчёте покупатель ещё может не ввести телефон, а API требует
                // валидный номер — подставляем телефон магазина: на цену он не влияет.
                'phone' => $this->normalizePhone($to['phone'] ?? null) ?: (string) $this->senderPhone,
                'email' => (string) ($to['email'] ?? ''),
            ],
        ]);

        if (! $response->successful()) {
            $this->logFailure('offers/create', $response);

            return null;
        }

        return $response->json('offers', []);
    }

    /** Подтверждение варианта доставки — вот это уже создаёт заявку в Яндексе. */
    public function confirmOffer(string $offerId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->client()->post('/offers/confirm', ['offer_id' => $offerId]);

        if (! $response->successful()) {
            $this->logFailure('offers/confirm', $response);

            return null;
        }

        return $response->json();
    }

    /** Состояние заявки. Этот метод у Яндекса — GET, в отличие от остальных. */
    public function requestInfo(string $requestId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $response = $this->client()->get('/request/info', ['request_id' => $requestId]);

        return $response->successful() ? $response->json() : null;
    }

    /**
     * Отмена заявки.
     *
     * @return array{ok: bool, reason?: string}
     */
    public function cancelRequest(string $requestId): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'reason' => 'не задан токен Яндекс Доставки'];
        }

        $response = $this->client()->post('/request/cancel', ['request_id' => $requestId]);

        if ($response->successful()) {
            return ['ok' => true];
        }

        $this->logFailure('request/cancel', $response);

        return ['ok' => false, 'reason' => (string) ($response->json('message') ?: 'Яндекс отклонил отмену')];
    }

    private function destination(array $to): ?array
    {
        if (! empty($to['point_id'])) {
            return ['platform_station' => ['platform_id' => (string) $to['point_id']]];
        }

        if (! empty($to['address'])) {
            $destination = ['custom_location' => ['details' => ['full_address' => (string) $to['address']]]];

            if (! empty($to['latitude']) && ! empty($to['longitude'])) {
                $destination['custom_location']['latitude'] = (float) $to['latitude'];
                $destination['custom_location']['longitude'] = (float) $to['longitude'];
            }

            return $destination;
        }

        return null;
    }

    /**
     * Цены Яндекс Доставки — в копейках у позиций и строкой «435.54 RUB» в ответе.
     */
    private function items(array $items, string $requestId, array $dims): array
    {
        $fallbackWeight = (int) ($dims['weight'] ?? 500);

        return collect($items)->map(fn ($item) => [
            'count' => max(1, (int) ($item['qty'] ?? 1)),
            'name' => (string) ($item['name'] ?? 'Товар'),
            'article' => (string) ($item['article'] ?? 'item'),
            'billing_details' => [
                'unit_price' => (int) round(((float) ($item['price'] ?? 0)) * 100),
                'assessed_unit_price' => (int) round(((float) ($item['price'] ?? 0)) * 100),
            ],
            'physical_dims' => [
                'weight_gross' => (int) ($item['weight'] ?: $fallbackWeight),
                'dx' => (int) ($dims['dx'] ?? 30),
                'dy' => (int) ($dims['dy'] ?? 25),
                'dz' => (int) ($dims['dz'] ?? 10),
            ],
            'place_barcode' => $requestId.'-1',
        ])->values()->all();
    }

    private function placeDims(array $items, array $dims): array
    {
        $fallbackWeight = (int) ($dims['weight'] ?? 500);
        $weight = collect($items)->sum(
            fn ($item) => (int) ($item['weight'] ?: $fallbackWeight) * max(1, (int) ($item['qty'] ?? 1))
        );

        return [
            'weight_gross' => max($fallbackWeight, (int) $weight),
            'dx' => (int) ($dims['dx'] ?? 30),
            'dy' => (int) ($dims['dy'] ?? 25),
            'dz' => (int) ($dims['dz'] ?? 10),
        ];
    }

    /** Яндекс принимает только формат +7XXXXXXXXXX. */
    private function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('~\D~', '', (string) $phone);

        if (mb_strlen((string) $digits) < 11) {
            return null;
        }

        return '+'.($digits[0] === '8' ? '7'.mb_substr($digits, 1) : $digits);
    }

    /** «435.54 RUB» → 435.54 */
    private function parsePrice(?string $price): ?float
    {
        if (! $price) {
            return null;
        }

        return preg_match('~([\d.]+)~', $price, $m) ? (float) $m[1] : null;
    }

    private function logFailure(string $endpoint, $response): void
    {
        Log::error('Yandex Delivery request failed', [
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ]);
    }
}
