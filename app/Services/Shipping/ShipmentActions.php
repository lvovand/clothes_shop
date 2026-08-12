<?php

namespace App\Services\Shipping;

use App\Models\Order;
use App\Models\Shipment;
use App\Services\Cdek\CdekClient;
use App\Services\Cdek\CdekDispatcher;
use App\Services\Telegram\TelegramNotifier;
use App\Services\YandexDelivery\YandexDeliveryClient;
use Illuminate\Support\Facades\Log;

/**
 * Ручные действия с заявкой на доставку: создать, отменить, подтянуть номер
 * накладной. Одна реализация на всех — этим пользуются и карточка заказа в
 * админке, и мини-приложение бота, где те же кнопки.
 */
class ShipmentActions
{
    /** Как перевозчик называется в подписях для человека. */
    public const CARRIERS = [
        'yandex' => 'Яндекс Доставка',
        'cdek' => 'СДЭК',
    ];

    public function __construct(
        private readonly ShipmentDispatcher $dispatcher,
        private readonly CdekDispatcher $cdek,
        private readonly CdekClient $cdekClient,
        private readonly YandexDeliveryClient $yandex,
    ) {
    }

    public function carrierName(Order $order): ?string
    {
        return self::CARRIERS[$order->shippingMethod?->provider()] ?? null;
    }

    /** Последняя заявка по заказу; $cancelled = false — только действующая. */
    public function shipment(Order $order, ?bool $cancelled = null): ?Shipment
    {
        $query = $order->shipments()->whereNotNull('tracking_number');

        if ($cancelled === false) {
            $query->where('status', '!=', 'cancelled');
        }

        return $query->latest('id')->first();
    }

    public function canCreate(Order $order): bool
    {
        return $this->carrierName($order) !== null && ! $this->shipment($order);
    }

    public function canCancel(Order $order): bool
    {
        return (bool) $this->shipment($order, cancelled: false);
    }

    /**
     * СДЭК оформляет заявку асинхронно: пока накладной нет, в tracking_number
     * лежит её uuid. Значит номер ещё можно и нужно дозапросить.
     */
    public function canRefreshNumber(Order $order): bool
    {
        $shipment = $this->shipment($order, cancelled: false);

        return $shipment
            && $shipment->provider === 'cdek'
            && $shipment->tracking_number === ($shipment->raw_response['uuid'] ?? null);
    }

    /**
     * Создание заявки вручную — идёт и при выключенном автосоздании.
     *
     * @return array{ok: bool, message: string, shipment: ?Shipment}
     */
    public function create(Order $order): array
    {
        $result = $this->dispatcher->dispatch($order, force: true);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'message' => $result['reason'] ?? 'Неизвестная причина',
                'shipment' => null,
            ];
        }

        $shipment = $result['shipment'];

        $this->notify(fn (TelegramNotifier $telegram) => $telegram->shipmentCreated(
            $order,
            (string) $shipment->tracking_number,
            $shipment->pvz_address,
        ));

        return [
            'ok' => true,
            'message' => 'Заявка создана, номер: '.$shipment->tracking_number,
            'shipment' => $shipment,
        ];
    }

    /** @return array{ok: bool, message: string} */
    public function cancel(Order $order): array
    {
        $shipment = $this->shipment($order, cancelled: false);

        if (! $shipment) {
            return ['ok' => false, 'message' => 'Действующей заявки нет.'];
        }

        $result = $shipment->provider === 'cdek'
            ? $this->cdekClient->deleteOrder((string) ($shipment->raw_response['uuid'] ?? ''))
            : $this->yandex->cancelRequest((string) $shipment->tracking_number);

        if (! $result['ok']) {
            return ['ok' => false, 'message' => $result['reason'] ?? 'Перевозчик отклонил отмену'];
        }

        $shipment->update(['status' => 'cancelled']);

        return ['ok' => true, 'message' => 'Заявка отменена.'];
    }

    /** @return array{ok: bool, message: string, number: ?string} */
    public function refreshNumber(Order $order): array
    {
        $shipment = $this->shipment($order, cancelled: false);

        if (! $shipment) {
            return ['ok' => false, 'message' => 'Действующей заявки нет.', 'number' => null];
        }

        $number = $this->cdek->refreshNumber($shipment);

        return [
            'ok' => (bool) $number,
            'message' => $number
                ? 'Номер накладной: '.$number
                : 'СДЭК ещё не выдал номер — заявка принята, накладная оформляется. Попробуйте через минуту.',
            'number' => $number,
        ];
    }

    private function notify(callable $callback): void
    {
        try {
            $callback(app(TelegramNotifier::class));
        } catch (\Throwable $e) {
            Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }
}
