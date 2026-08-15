<?php

namespace App\Services\YandexDelivery;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\SiteSetting;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Support\Facades\Log;

/**
 * Создание заявки на доставку в Яндексе по оплаченному заказу.
 *
 * Оффер (расчёт) живёт около 15 минут, поэтому его нельзя сохранить на чекауте и
 * подтвердить после оплаты — вариант пересобирается заново прямо здесь и сразу
 * подтверждается. Идемпотентность держим по своей записи `shipments`: повторный
 * вебхук эквайера не должен создавать вторую заявку.
 */
class YandexDeliveryDispatcher
{
    public function __construct(
        private readonly YandexDeliveryClient $client,
        private readonly StockService $stock,
    ) {
    }

    /** Причины, о которых не нужно поднимать шум: это не сбой, а настройка. */
    public const REASON_NOT_YANDEX = 'способ доставки заказа — не Яндекс';

    public const REASON_AUTO_OFF = 'автосоздание заявок выключено в настройках';

    public const REASON_ALREADY = 'заявка уже создана';

    /**
     * @param  bool  $force  создание из админки — идёт и при выключенном автосоздании
     * @return array{ok: bool, shipment?: Shipment, shipments?: array<int, Shipment>, reason?: string}
     */
    public function dispatch(Order $order, bool $force = false): array
    {
        if (! $force && ! (bool) SiteSetting::get('yandex_auto_create', true)) {
            return ['ok' => false, 'reason' => self::REASON_AUTO_OFF];
        }

        $order->loadMissing('items.variant', 'shippingMethod');

        $method = $order->shippingMethod;

        if (! $method || $method->provider() !== 'yandex') {
            return ['ok' => false, 'reason' => self::REASON_NOT_YANDEX];
        }

        $destination = $this->destination($order, $method->needsPickupPoint());

        if (! $destination) {
            return ['ok' => false, 'reason' => 'в заказе нет пункта выдачи или адреса'];
        }

        // Заказ, разложенный на два склада, едет двумя отправлениями: у каждого
        // своя точка сдачи, а значит и своя заявка.
        $groups = $this->stock->orderShipmentGroups($order) ?: [0 => []];
        $warehouses = Warehouse::whereIn('id', array_keys($groups))->get()->keyBy('id');

        $existing = $order->shipments()
            ->where('provider', 'yandex')
            ->whereNotNull('tracking_number')
            ->get();

        $done = [];
        $created = [];
        $failed = [];

        foreach ($groups as $warehouseId => $items) {
            $already = $existing->first(fn (Shipment $shipment) => (int) $shipment->warehouse_id === (int) $warehouseId);

            if ($already) {
                $done[] = $already;

                continue;
            }

            $warehouse = $warehouses->get($warehouseId);
            $source = $warehouse?->yandex_dropoff_id;

            if (! $this->client->canCalculate($source)) {
                return ['ok' => false, 'reason' => 'не заданы токен Яндекс Доставки или точка сдачи склада «'.($warehouse?->name ?? '—').'» (Склад → Склады)'];
            }

            // Идентификатор заявки у Яндекса уникален: у второго отправления к
            // номеру заказа добавляется код склада.
            $requestId = (string) ($order->order_number ?: $order->id)
                .(count($groups) > 1 && $warehouse ? '-'.$warehouse->code : '');

            $result = $this->createRequest($order, $destination, $requestId, $items, $source);

            if (! $result['ok']) {
                $failed[] = $result['reason'];

                continue;
            }

            $created[] = $order->shipments()->create([
                'provider' => 'yandex',
                'warehouse_id' => $warehouseId ?: null,
                'tracking_number' => $result['tracking_number'],
                'pvz_code' => $destination['point_id'] ?? null,
                'pvz_address' => $order->shipping_address['pvz_address'] ?? null,
                'status' => 'created',
                'raw_response' => $result['raw'],
            ]);

            Log::info('Yandex Delivery request created', [
                'order' => $requestId,
                'warehouse' => $warehouseId,
                'request_id' => $result['tracking_number'],
                'cost' => $result['cost'],
            ]);
        }

        if (! $created) {
            return $done
                ? ['ok' => true, 'shipment' => $done[0], 'shipments' => $done, 'reason' => self::REASON_ALREADY]
                : ['ok' => false, 'reason' => $failed[0] ?? 'Яндекс не вернул вариантов доставки'];
        }

        $all = array_merge($done, $created);

        return [
            'ok' => true,
            'shipment' => $all[0],
            'shipments' => $all,
            // Одно из двух отправлений не прошло — молчать об этом нельзя.
            'reason' => $failed ? implode('; ', $failed) : null,
        ];
    }

    /**
     * Оффер и его подтверждение по одному отправлению.
     *
     * @param  array<int, int>  $only  что уезжает с этого склада; пусто — весь заказ
     * @return array{ok: bool, tracking_number?: string, cost?: ?float, raw?: array, reason?: string}
     */
    private function createRequest(Order $order, array $destination, string $requestId, array $only, ?string $source): array
    {
        $offers = $this->client->offers($this->items($order, $only), $destination, $requestId, $this->dims(), $source);

        if (! $offers) {
            return ['ok' => false, 'reason' => 'Яндекс не вернул вариантов доставки'];
        }

        // Берём самый дешёвый вариант — тот же принцип, по которому покупателю
        // показывалась цена на чекауте.
        $best = collect($offers)
            ->map(fn ($offer) => [
                'offer_id' => (string) ($offer['offer_id'] ?? ''),
                'cost' => $this->price($offer['offer_details']['pricing_total'] ?? null),
                'raw' => $offer,
            ])
            ->filter(fn ($offer) => $offer['offer_id'] !== '' && $offer['cost'] !== null)
            ->sortBy('cost')
            ->first();

        if (! $best) {
            return ['ok' => false, 'reason' => 'в ответе Яндекса нет пригодного варианта'];
        }

        $confirmed = $this->client->confirmOffer($best['offer_id']);

        if (! $confirmed) {
            return ['ok' => false, 'reason' => 'Яндекс отклонил подтверждение варианта доставки'];
        }

        return [
            'ok' => true,
            'tracking_number' => (string) ($confirmed['request_id'] ?? $confirmed['id'] ?? ''),
            'cost' => $best['cost'],
            'raw' => ['offer' => $best['raw'], 'confirm' => $confirmed],
        ];
    }

    /** @return array<string, mixed>|null */
    private function destination(Order $order, bool $needsPickupPoint): ?array
    {
        $address = $order->shipping_address ?? [];

        [$firstName, $lastName] = $this->splitName((string) $order->customer_name);

        $base = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => (string) $order->customer_phone,
            'email' => (string) ($order->customer_email ?? ''),
        ];

        if ($needsPickupPoint) {
            if (empty($address['pvz_code'])) {
                return null;
            }

            return $base + ['point_id' => (string) $address['pvz_code']];
        }

        $line = trim(implode(', ', array_filter([$address['city'] ?? null, $address['address'] ?? null])));

        if ($line === '') {
            return null;
        }

        return $base + ['address' => $line];
    }

    /**
     * @param  array<int, int>  $only  [variant_id => qty] этого отправления; пусто — весь заказ
     * @return array<int, array<string, mixed>>
     */
    private function items(Order $order, array $only = []): array
    {
        return $order->items
            ->filter(fn ($item) => ! $only || ($only[$item->variant_id] ?? 0) > 0)
            ->values()
            ->map(fn ($item) => [
                'name' => (string) ($item->product_title_snapshot ?: 'Товар'),
                'article' => (string) ($item->variant?->sku ?: 'variant-'.$item->variant_id),
                'qty' => $only ? (int) min($item->qty, $only[$item->variant_id]) : (int) $item->qty,
                'price' => (float) $item->unit_price,
                'weight' => $item->variant?->product?->weight_kg
                    ? (int) round((float) $item->variant->product->weight_kg * 1000)
                    : null,
            ])->all();
    }

    /** @return array{weight: int, dx: int, dy: int, dz: int} */
    private function dims(): array
    {
        return [
            'weight' => (int) (SiteSetting::get('parcel_weight') ?: 500),
            'dx' => (int) (SiteSetting::get('parcel_dx') ?: 30),
            'dy' => (int) (SiteSetting::get('parcel_dy') ?: 25),
            'dz' => (int) (SiteSetting::get('parcel_dz') ?: 10),
        ];
    }

    /** @return array{0: string, 1: string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('~\s+~', trim($name), 2) ?: [];

        return [$parts[0] ?? 'Покупатель', $parts[1] ?? ''];
    }

    private function price(?string $price): ?float
    {
        return $price && preg_match('~([\d.]+)~', $price, $m) ? (float) $m[1] : null;
    }
}
