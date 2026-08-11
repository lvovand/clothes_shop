<?php

namespace App\Services\Cdek;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * Создание заявки в СДЭК по оплаченному заказу — то же, что делает
 * YandexDeliveryDispatcher для Яндекса, но у СДЭК другая механика:
 *
 * - заявка создаётся одним запросом (у Яндекса — оффер + подтверждение),
 * - ответ асинхронный: сразу приходит только uuid, номер накладной появляется
 *   через несколько секунд, поэтому его дозапрашиваем,
 * - тарифы магазина «склад–склад» и «склад–дверь», то есть посылку везут в пункт
 *   приёма СДЭК: его код задаётся в настройках («Пункт сдачи посылок»).
 *
 * Идемпотентность — по своей записи `shipments`: повторный вебхук эквайера не
 * должен создавать вторую заявку.
 */
class CdekDispatcher
{
    public function __construct(private readonly CdekClient $client)
    {
    }

    public const REASON_NOT_CDEK = 'способ доставки заказа — не СДЭК';

    public const REASON_AUTO_OFF = 'автосоздание заявок выключено в настройках';

    public const REASON_ALREADY = 'заявка уже создана';

    /**
     * @param  bool  $force  создание из админки — идёт и при выключенном автосоздании
     * @return array{ok: bool, shipment?: Shipment, reason?: string}
     */
    public function dispatch(Order $order, bool $force = false): array
    {
        $prepared = $this->prepare($order, $force);

        if (! $prepared['ok']) {
            return $prepared;
        }

        // Уже созданная заявка возвращается как есть — повторный вебхук не должен
        // заводить вторую.
        if (isset($prepared['shipment'])) {
            return $prepared;
        }

        $payload = $prepared['payload'];

        $created = $this->client->createOrder($payload);

        if (! $created) {
            return ['ok' => false, 'reason' => 'СДЭК отклонил создание заявки, подробности в журнале сайта'];
        }

        // Номер накладной появляется не мгновенно: заявка обрабатывается на стороне
        // СДЭК. Пробуем несколько раз, а если не дождались — сохраняем uuid, номер
        // подтянет кнопка «Обновить номер накладной» в карточке заказа.
        $info = $this->awaitNumber($created['uuid']);

        $shipment = $order->shipments()->create([
            'provider' => 'cdek',
            'tracking_number' => $info['number'] ?? $created['uuid'],
            'pvz_code' => $order->shipping_address['pvz_code'] ?? null,
            'pvz_address' => $order->shipping_address['pvz_address'] ?? null,
            'status' => 'created',
            'raw_response' => ['uuid' => $created['uuid'], 'create' => $created['raw'], 'info' => $info['raw'] ?? null],
        ]);

        Log::info('CDEK order created', [
            'order' => $payload['number'],
            'uuid' => $created['uuid'],
            'cdek_number' => $info['number'] ?? null,
        ]);

        return ['ok' => true, 'shipment' => $shipment];
    }
    /**
     * Проверки и сборка запроса — отдельно от отправки, чтобы состав заявки можно
     * было посмотреть, ничего не создавая (`preview`).
     *
     * @return array{ok: bool, payload?: array, shipment?: Shipment, reason?: string}
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

        $existing = $order->shipments()
            ->where('provider', 'cdek')
            ->whereNotNull('tracking_number')
            ->first();

        if ($existing) {
            return ['ok' => true, 'shipment' => $existing, 'reason' => self::REASON_ALREADY];
        }

        if (! $this->client->isConfigured()) {
            return ['ok' => false, 'reason' => 'не заданы ключи СДЭК'];
        }

        $shipmentPoint = trim((string) SiteSetting::get('cdek_shipment_point', ''));

        if ($shipmentPoint === '') {
            return ['ok' => false, 'reason' => 'не выбран пункт сдачи посылок СДЭК (Настройки → Доставка и оплата)'];
        }

        $tariff = (int) ($method->config['tariff_code'] ?? 0);

        if ($tariff === 0) {
            return ['ok' => false, 'reason' => 'у способа доставки не задан код тарифа СДЭК'];
        }

        $destination = $this->destination($order, $method->needsPickupPoint());

        if (! $destination) {
            return ['ok' => false, 'reason' => 'в заказе нет пункта выдачи или адреса'];
        }

        $payload = [
            'type' => 1, // интернет-магазин
            'number' => (string) ($order->order_number ?: $order->id),
            'tariff_code' => $tariff,
            'shipment_point' => $shipmentPoint,
            'recipient' => [
                'name' => (string) ($order->customer_name ?: 'Покупатель'),
                'phones' => [['number' => (string) $order->customer_phone]],
            ],
            'packages' => [$this->package($order)],
        ] + $destination;

        if ($order->customer_email) {
            $payload['recipient']['email'] = (string) $order->customer_email;
        }

        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * Что уйдёт в СДЭК по этому заказу — для проверки настроек без создания заявки.
     *
     * @return array{ok: bool, payload?: array, reason?: string}
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

    /** @return array<string, mixed> */
    private function package(Order $order): array
    {
        $default = (int) (SiteSetting::get('parcel_weight') ?: 500);

        $items = $order->items->map(function ($item) use ($default) {
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
                'amount' => (int) $item->qty,
                'weight' => $weight,
            ];
        })->all();

        $total = collect($items)->sum(fn ($item) => $item['weight'] * $item['amount']);

        return [
            'number' => (string) ($order->order_number ?: $order->id),
            'weight' => max(1, (int) $total),
            'length' => (int) (SiteSetting::get('parcel_dx') ?: 30),
            'width' => (int) (SiteSetting::get('parcel_dy') ?: 25),
            'height' => (int) (SiteSetting::get('parcel_dz') ?: 10),
            'items' => $items,
        ];
    }
}
