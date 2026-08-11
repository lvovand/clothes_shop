<?php

namespace App\Services\YandexDelivery;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\SiteSetting;
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
    public function __construct(private readonly YandexDeliveryClient $client)
    {
    }

    /** Причины, о которых не нужно поднимать шум: это не сбой, а настройка. */
    public const REASON_NOT_YANDEX = 'способ доставки заказа — не Яндекс';

    public const REASON_AUTO_OFF = 'автосоздание заявок выключено в настройках';

    public const REASON_ALREADY = 'заявка уже создана';

    /**
     * @param  bool  $force  создание из админки — идёт и при выключенном автосоздании
     * @return array{ok: bool, shipment?: Shipment, reason?: string}
     */
    public function dispatch(Order $order, bool $force = false): array
    {
        if (! $force && ! (bool) SiteSetting::get('delivery_auto_create', true)) {
            return ['ok' => false, 'reason' => self::REASON_AUTO_OFF];
        }

        $order->loadMissing('items.variant', 'shippingMethod');

        $method = $order->shippingMethod;

        if (! $method || $method->provider() !== 'yandex') {
            return ['ok' => false, 'reason' => self::REASON_NOT_YANDEX];
        }

        $existing = $order->shipments()
            ->where('provider', 'yandex')
            ->whereNotNull('tracking_number')
            ->first();

        if ($existing) {
            return ['ok' => true, 'shipment' => $existing, 'reason' => self::REASON_ALREADY];
        }

        if (! $this->client->canCalculate()) {
            return ['ok' => false, 'reason' => 'не заданы токен или точка сдачи Яндекс Доставки'];
        }

        $destination = $this->destination($order, $method->needsPickupPoint());

        if (! $destination) {
            return ['ok' => false, 'reason' => 'в заказе нет пункта выдачи или адреса'];
        }

        $requestId = (string) ($order->order_number ?: $order->id);

        $offers = $this->client->offers($this->items($order), $destination, $requestId, $this->dims());

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

        $shipment = $order->shipments()->create([
            'provider' => 'yandex',
            'tracking_number' => (string) ($confirmed['request_id'] ?? $confirmed['id'] ?? ''),
            'pvz_code' => $destination['point_id'] ?? null,
            'pvz_address' => $order->shipping_address['pvz_address'] ?? null,
            'status' => 'created',
            'raw_response' => ['offer' => $best['raw'], 'confirm' => $confirmed],
        ]);

        Log::info('Yandex Delivery request created', [
            'order' => $requestId,
            'request_id' => $shipment->tracking_number,
            'cost' => $best['cost'],
        ]);

        return ['ok' => true, 'shipment' => $shipment];
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

    /** @return array<int, array<string, mixed>> */
    private function items(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'name' => (string) ($item->product_title_snapshot ?: 'Товар'),
            'article' => (string) ($item->variant?->sku ?: 'variant-'.$item->variant_id),
            'qty' => (int) $item->qty,
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
