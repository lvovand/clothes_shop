<?php

namespace App\Services\Cdek;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\SiteSetting;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Support\Facades\Log;

/**
 * Создание заявки в СДЭК по оплаченному заказу — то же, что делает
 * YandexDeliveryDispatcher для Яндекса, но у СДЭК другая механика:
 *
 * - заявка создаётся одним запросом (у Яндекса — оффер + подтверждение),
 * - ответ асинхронный: сразу приходит только uuid, номер накладной появляется
 *   через несколько секунд, поэтому его дозапрашиваем,
 * - тарифы магазина «склад–склад» и «склад–дверь», то есть посылку везут в пункт
 *   приёма СДЭК: его код задаётся у склада отгрузки («Склады» в админке).
 *
 * Заказ, разложенный на два склада, едет двумя отправлениями — по заявке на
 * каждый склад, со своим городом отправления и своим пунктом сдачи.
 *
 * Идемпотентность — по своей записи `shipments`: повторный вебхук эквайера не
 * должен создавать вторую заявку по тому же складу.
 */
class CdekDispatcher
{
    public function __construct(
        private readonly CdekClient $client,
        private readonly StockService $stock,
    ) {
    }

    public const REASON_NOT_CDEK = 'способ доставки заказа — не СДЭК';

    public const REASON_AUTO_OFF = 'автосоздание заявок выключено в настройках';

    public const REASON_ALREADY = 'заявка уже создана';

    /**
     * @param  bool  $force  создание из админки — идёт и при выключенном автосоздании
     * @return array{ok: bool, shipment?: Shipment, shipments?: array<int, Shipment>, reason?: string}
     */
    public function dispatch(Order $order, bool $force = false): array
    {
        $prepared = $this->prepare($order, $force);

        if (! $prepared['ok']) {
            return $prepared;
        }

        $shipments = $prepared['shipments'];
        $created = [];
        $failed = [];

        foreach ($prepared['payloads'] as $warehouseId => $payload) {
            $response = $this->client->createOrder($payload);

            if (! $response) {
                $failed[] = 'СДЭК отклонил создание заявки, подробности в журнале сайта';

                continue;
            }

            // Номер накладной появляется не мгновенно: заявка обрабатывается на стороне
            // СДЭК. Пробуем несколько раз, а если не дождались — сохраняем uuid, номер
            // подтянет кнопка «Обновить номер накладной» в карточке заказа.
            $info = $this->awaitNumber($response['uuid']);

            $created[] = $order->shipments()->create([
                'provider' => 'cdek',
                'warehouse_id' => $warehouseId ?: null,
                'tracking_number' => $info['number'] ?? $response['uuid'],
                'pvz_code' => $order->shipping_address['pvz_code'] ?? null,
                'pvz_address' => $order->shipping_address['pvz_address'] ?? null,
                'status' => 'created',
                'raw_response' => ['uuid' => $response['uuid'], 'create' => $response['raw'], 'info' => $info['raw'] ?? null],
            ]);

            Log::info('CDEK order created', [
                'order' => $payload['number'],
                'warehouse' => $warehouseId,
                'uuid' => $response['uuid'],
                'cdek_number' => $info['number'] ?? null,
            ]);
        }

        if (! $created) {
            return ['ok' => false, 'reason' => $failed[0] ?? self::REASON_ALREADY];
        }

        $all = array_merge($shipments, $created);

        return [
            'ok' => true,
            'shipment' => $all[0],
            'shipments' => $all,
            // Одна из двух заявок не прошла — заказ уехал наполовину, об этом
            // нужно сказать, а не отрапортовать успех.
            'reason' => $failed ? implode('; ', $failed) : null,
        ];
    }

    /**
     * Проверки и сборка запросов — отдельно от отправки, чтобы состав заявки можно
     * было посмотреть, ничего не создавая (`preview`).
     *
     * @return array{ok: bool, payloads?: array<int, array>, shipments?: array<int, Shipment>, shipment?: Shipment, reason?: string}
     */
    private function prepare(Order $order, bool $force): array
    {
        if (! $force && ! (bool) SiteSetting::get('cdek_auto_create', true)) {
            return ['ok' => false, 'reason' => self::REASON_AUTO_OFF];
        }

        $order->loadMissing('items.variant.product', 'shippingMethod');

        $method = $order->shippingMethod;

        if (! $method || $method->provider() !== 'cdek') {
            return ['ok' => false, 'reason' => self::REASON_NOT_CDEK];
        }

        if (! $this->client->isConfigured()) {
            return ['ok' => false, 'reason' => 'не заданы ключи СДЭК'];
        }

        $tariff = (int) ($method->config['tariff_code'] ?? 0);

        if ($tariff === 0) {
            return ['ok' => false, 'reason' => 'у способа доставки не задан код тарифа СДЭК'];
        }

        $destination = $this->destination($order, $method->needsPickupPoint());

        if (! $destination) {
            return ['ok' => false, 'reason' => 'в заказе нет пункта выдачи или адреса'];
        }

        $groups = $this->stock->orderShipmentGroups($order) ?: [0 => []];
        $warehouses = Warehouse::whereIn('id', array_keys($groups))->get()->keyBy('id');

        $existing = $order->shipments()
            ->where('provider', 'cdek')
            ->whereNotNull('tracking_number')
            ->get();

        $payloads = [];
        $done = [];

        foreach ($groups as $warehouseId => $items) {
            $already = $existing->first(fn (Shipment $shipment) => (int) $shipment->warehouse_id === (int) $warehouseId);

            if ($already) {
                $done[] = $already;

                continue;
            }

            $warehouse = $warehouses->get($warehouseId);
            $shipmentPoint = trim((string) ($warehouse?->cdek_shipment_point
                ?: SiteSetting::get('cdek_shipment_point', '')));

            if ($shipmentPoint === '') {
                return ['ok' => false, 'reason' => 'у склада «'.($warehouse?->name ?? '—').'» не выбран пункт сдачи посылок СДЭК (Склад → Склады)'];
            }

            $payload = [
                'type' => 1, // интернет-магазин
                // Номер заказа у СДЭК уникален: при двух отправлениях к нему
                // добавляется код склада, иначе вторая заявка не создастся.
                'number' => $this->shipmentNumber($order, $warehouse, count($groups) > 1),
                'tariff_code' => $tariff,
                'shipment_point' => $shipmentPoint,
                'recipient' => [
                    'name' => (string) ($order->customer_name ?: 'Покупатель'),
                    'phones' => [['number' => (string) $order->customer_phone]],
                ],
                'packages' => [$this->package($order, $items, $this->shipmentNumber($order, $warehouse, count($groups) > 1))],
            ] + $destination;

            if ($order->customer_email) {
                $payload['recipient']['email'] = (string) $order->customer_email;
            }

            $payloads[(int) $warehouseId] = $payload;
        }

        if (! $payloads) {
            return ['ok' => true, 'shipment' => $done[0] ?? null, 'shipments' => $done, 'reason' => self::REASON_ALREADY];
        }

        return ['ok' => true, 'payloads' => $payloads, 'shipments' => $done];
    }

    /**
     * Что уйдёт в СДЭК по этому заказу — для проверки настроек без создания заявки.
     *
     * @return array{ok: bool, payloads?: array<int, array>, reason?: string}
     */
    public function preview(Order $order): array
    {
        return $this->prepare($order, force: true);
    }

    /** Подтягивает номер накладной для уже созданной заявки. */
    public function refreshNumber(Shipment $shipment): ?string
    {
        $uuid = (string) ($shipment->raw_response['uuid'] ?? '');

        if ($uuid === '') {
            return null;
        }

        $info = $this->client->orderInfo($uuid);

        if (! $info || ! $info['number']) {
            return null;
        }

        $shipment->update([
            'tracking_number' => $info['number'],
            'raw_response' => array_merge((array) $shipment->raw_response, ['info' => $info['raw']]),
        ]);

        return $info['number'];
    }

    /** @return array{number: ?string, raw: ?array} */
    private function awaitNumber(string $uuid): array
    {
        foreach ([1, 2, 3] as $attempt) {
            $info = $this->client->orderInfo($uuid);

            if ($info && $info['number']) {
                return ['number' => $info['number'], 'raw' => $info['raw']];
            }

            if ($attempt < 3) {
                usleep(1_500_000);
            }
        }

        return ['number' => null, 'raw' => null];
    }

    /** Номер заказа для перевозчика: у второго отправления он свой. */
    private function shipmentNumber(Order $order, ?Warehouse $warehouse, bool $split): string
    {
        $number = (string) ($order->order_number ?: $order->id);

        return $split && $warehouse ? $number.'-'.$warehouse->code : $number;
    }

    /**
     * Куда везём: пункт выдачи задаётся кодом, курьерская доставка — городом и
     * адресом. Город нужен кодом справочника СДЭК, иначе адрес не разберётся.
     *
     * @return array<string, mixed>|null
     */
    private function destination(Order $order, bool $needsPickupPoint): ?array
    {
        $address = $order->shipping_address ?? [];

        if ($needsPickupPoint) {
            return empty($address['pvz_code'])
                ? null
                : ['delivery_point' => (string) $address['pvz_code']];
        }

        $city = trim((string) ($address['city'] ?? ''));
        $line = trim((string) ($address['address'] ?? ''));

        if ($city === '' || $line === '') {
            return null;
        }

        $code = $this->client->findCityCode($city);

        if (! $code) {
            return null;
        }

        return ['to_location' => ['code' => $code, 'address' => $line]];
    }

    /**
     * Состав посылки. `$only` — что именно уезжает с этого склада
     * ([variant_id => qty]); пустой массив означает «весь заказ», так посылка
     * собирается у заказов без складской раскладки.
     *
     * @param  array<int, int>  $only
     * @return array<string, mixed>
     */
    private function package(Order $order, array $only = [], ?string $number = null): array
    {
        $default = (int) (SiteSetting::get('parcel_weight') ?: 500);

        $items = $order->items
            ->filter(fn ($item) => ! $only || ($only[$item->variant_id] ?? 0) > 0)
            ->map(function ($item) use ($default, $only) {
                $weight = $item->variant?->product?->weight_kg
                    ? (int) round((float) $item->variant->product->weight_kg * 1000)
                    : $default;

                return [
                    'name' => (string) ($item->product_title_snapshot ?: 'Товар'),
                    'ware_key' => (string) ($item->variant?->sku ?: 'variant-'.$item->variant_id),
                    'cost' => (float) $item->unit_price,
                    // Наложенный платёж: у СДЭК оплата при получении не разрешена
                    // (cod_allowed = 0), заказы всегда предоплачены.
                    'payment' => ['value' => 0],
                    'amount' => $only ? (int) min($item->qty, $only[$item->variant_id]) : (int) $item->qty,
                    'weight' => $weight,
                ];
            })
            ->values()
            ->all();

        $total = collect($items)->sum(fn ($item) => $item['weight'] * $item['amount']);

        return [
            'number' => $number ?: (string) ($order->order_number ?: $order->id),
            'weight' => max(1, (int) $total),
            'length' => (int) (SiteSetting::get('parcel_dx') ?: 30),
            'width' => (int) (SiteSetting::get('parcel_dy') ?: 25),
            'height' => (int) (SiteSetting::get('parcel_dz') ?: 10),
            'items' => $items,
        ];
    }
}
