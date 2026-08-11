<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Cdek\CdekDispatcher;
use App\Services\YandexDelivery\YandexDeliveryDispatcher;

/**
 * Одна точка входа для создания заявки на доставку: перевозчик выбирается по
 * способу доставки заказа. Вебхуку эквайера, оформлению и админке не нужно знать,
 * кто везёт — иначе каждое новое подключение требовало бы правок в трёх местах.
 */
class ShipmentDispatcher
{
    public function __construct(
        private readonly YandexDeliveryDispatcher $yandex,
        private readonly CdekDispatcher $cdek,
    ) {
    }

    /** Причины, о которых не нужно поднимать шум: это не сбой, а настройка. */
    public const REASON_NO_CARRIER = 'у способа доставки нет перевозчика';

    public const REASON_AUTO_OFF = YandexDeliveryDispatcher::REASON_AUTO_OFF;

    public const REASON_ALREADY = YandexDeliveryDispatcher::REASON_ALREADY;

    /**
     * @param  bool  $force  создание из админки — идёт и при выключенном автосоздании
     * @return array{ok: bool, shipment?: Shipment, reason?: string}
     */
    public function dispatch(Order $order, bool $force = false): array
    {
        return match ($order->shippingMethod?->provider()) {
            'yandex' => $this->yandex->dispatch($order, $force),
            'cdek' => $this->cdek->dispatch($order, $force),
            default => ['ok' => false, 'reason' => self::REASON_NO_CARRIER],
        };
    }

    /** Причины, при которых заявки просто не должно быть — молчим. */
    public function isQuiet(?string $reason): bool
    {
        return in_array($reason, [
            self::REASON_NO_CARRIER,
            self::REASON_AUTO_OFF,
            YandexDeliveryDispatcher::REASON_NOT_YANDEX,
            CdekDispatcher::REASON_NOT_CDEK,
        ], true);
    }
}
