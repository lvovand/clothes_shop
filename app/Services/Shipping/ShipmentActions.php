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
        private readonly \App\Services\StockService $stock,
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

    /**
     * Заказ может ехать двумя отправлениями (товар лежит на двух складах),
     * поэтому кнопка остаётся доступной, пока заявка есть не по каждому складу.
     */
    public function canCreate(Order $order): bool
    {
        if ($this->carrierName($order) === null) {
            return false;
        }

        $groups = array_keys($this->stock->orderShipmentGroups($order));

        if (! $groups) {
            return ! $this->shipment($order);
        }

        $covered = $order->shipments()
            ->whereNotNull('tracking_number')
            ->where('status', '!=', 'cancelled')
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return (bool) array_diff($groups, $covered);
    }

    public function canCancel(Order $order): bool
    {
        return $this->shipments($order, cancelled: false)->isNotEmpty();
    }

    /**
     * Все заявки по заказу; $cancelled = false — только действующие.
     *
     * @return \Illuminate\Support\Collection<int, Shipment>
     */
    public function shipments(Order $order, ?bool $cancelled = null): \Illuminate\Support\Collection
    {
        $query = $order->shipments()->whereNotNull('tracking_number');

        if ($cancelled === false) {
            $query->where('status', '!=', 'cancelled');
        }

        return $query->orderBy('id')->get();
    }

    /**
     * СДЭК оформляет заявку асинхронно: пока накладной нет, в tracking_number
     * лежит её uuid. Значит номер ещё можно и нужно дозапросить.
     */
    public function canRefreshNumber(Order $order): bool
    {
        return $this->awaitingNumber($order)->isNotEmpty();
    }

    /**
     * Заявки СДЭК, у которых номера накладной ещё нет.
     *
     * @return \Illuminate\Support\Collection<int, Shipment>
     */
    private function awaitingNumber(Order $order): \Illuminate\Support\Collection
    {
        return $this->shipments($order, cancelled: false)
            ->filter(fn (Shipment $shipment) => $shipment->provider === 'cdek'
                && $shipment->tracking_number === ($shipment->raw_response['uuid'] ?? null));
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

        $shipments = $result['shipments'] ?? array_filter([$result['shipment'] ?? null]);

        foreach ($shipments as $shipment) {
            $this->notify(fn (TelegramNotifier $telegram) => $telegram->shipmentCreated(
                $order,
                (string) $shipment->tracking_number,
                $shipment->pvz_address,
            ));
        }

        $numbers = collect($shipments)
            ->map(fn (Shipment $shipment) => $this->label($shipment))
            ->implode(', ');

        return [
            'ok' => true,
            // Заказ с двух складов едет двумя отправлениями — номеров тоже два.
            'message' => (count($shipments) > 1 ? 'Заявки созданы, номера: ' : 'Заявка создана, номер: ').$numbers
                .($result['reason'] ?? null ? '. Не удалось: '.$result['reason'] : ''),
            'shipment' => $shipments[0] ?? null,
        ];
    }

    /** Номер заявки со складом отгрузки, когда отправлений несколько. */
    private function label(Shipment $shipment): string
    {
        $warehouse = $shipment->warehouse?->name;

        return $warehouse ? $shipment->tracking_number.' ('.$warehouse.')' : (string) $shipment->tracking_number;
    }

    /** @return array{ok: bool, message: string} */
    public function cancel(Order $order): array
    {
        $shipments = $this->shipments($order, cancelled: false);

        if ($shipments->isEmpty()) {
            return ['ok' => false, 'message' => 'Действующей заявки нет.'];
        }

        $cancelled = 0;
        $errors = [];

        foreach ($shipments as $shipment) {
            $result = $shipment->provider === 'cdek'
                ? $this->cdekClient->deleteOrder((string) ($shipment->raw_response['uuid'] ?? ''))
                : $this->yandex->cancelRequest((string) $shipment->tracking_number);

            if (! $result['ok']) {
                $errors[] = $this->label($shipment).': '.($result['reason'] ?? 'перевозчик отклонил отмену');

                continue;
            }

            $shipment->update(['status' => 'cancelled']);
            $cancelled++;
        }

        if (! $cancelled) {
            return ['ok' => false, 'message' => implode('; ', $errors)];
        }

        return [
            'ok' => true,
            'message' => ($cancelled > 1 ? 'Заявки отменены.' : 'Заявка отменена.')
                .($errors ? ' Не удалось: '.implode('; ', $errors) : ''),
        ];
    }

    /** @return array{ok: bool, message: string, number: ?string} */
    public function refreshNumber(Order $order): array
    {
        $awaiting = $this->awaitingNumber($order);

        if ($awaiting->isEmpty()) {
            return ['ok' => false, 'message' => 'Действующей заявки нет.', 'number' => null];
        }

        $numbers = $awaiting
            ->map(fn (Shipment $shipment) => $this->cdek->refreshNumber($shipment))
            ->filter()
            ->values();

        return [
            'ok' => $numbers->isNotEmpty(),
            'message' => $numbers->isNotEmpty()
                ? 'Номер накладной: '.$numbers->implode(', ')
                : 'СДЭК ещё не выдал номер — заявка принята, накладная оформляется. Попробуйте через минуту.',
            'number' => $numbers->first(),
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
