<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Shipment;
use App\Services\Cdek\CdekClient;
use App\Services\Cdek\CdekDispatcher;
use App\Services\Shipping\ShipmentDispatcher;
use App\Services\Telegram\TelegramNotifier;
use App\Services\YandexDelivery\YandexDeliveryClient;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /** Как перевозчик называется в подписях кнопок. */
    private const CARRIERS = [
        'yandex' => 'Яндекс Доставке',
        'cdek' => 'СДЭК',
    ];

    protected function getHeaderActions(): array
    {
        return [
            $this->createShipmentAction(),
            $this->refreshCdekNumberAction(),
            $this->cancelShipmentAction(),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    private function carrier(): ?string
    {
        $provider = $this->record->shippingMethod?->provider();

        return isset(self::CARRIERS[$provider]) ? $provider : null;
    }

    /**
     * Заявка обычно создаётся сама после оплаты (настройка «Доставка и оплата» →
     * «Создавать заявку на доставку автоматически»). Кнопка нужна для случаев, когда
     * автосоздание выключено или один раз не сработало.
     */
    private function createShipmentAction(): Actions\Action
    {
        return Actions\Action::make('createShipment')
            ->label(fn () => 'Создать заявку в '.(self::CARRIERS[$this->carrier()] ?? 'службе доставки'))
            ->icon('heroicon-o-truck')
            ->requiresConfirmation()
            ->modalDescription(fn () => $this->carrier() === 'cdek'
                ? 'Заявка будет создана в СДЭК по данным этого заказа: тариф берётся из способа доставки, посылка отправляется из пункта сдачи, указанного в настройках.'
                : 'Заявка будет создана в Яндекс Доставке по данным этого заказа. Стоимость доставки берётся самая дешёвая из предложенных Яндексом.')
            ->visible(fn () => $this->carrier() !== null && ! $this->shipment())
            ->action(function () {
                $result = app(ShipmentDispatcher::class)->dispatch($this->record, force: true);

                if (! $result['ok']) {
                    Notification::make()
                        ->title('Заявку создать не удалось')
                        ->body($result['reason'] ?? 'Неизвестная причина')
                        ->danger()
                        ->send();

                    return;
                }

                $shipment = $result['shipment'];

                Notification::make()
                    ->title('Заявка создана')
                    ->body('Номер заявки: '.$shipment->tracking_number)
                    ->success()
                    ->send();

                $this->notifyTelegram(fn (TelegramNotifier $telegram) => $telegram->shipmentCreated(
                    $this->record,
                    (string) $shipment->tracking_number,
                    $shipment->pvz_address,
                ));
            });
    }

    /**
     * СДЭК оформляет заявку асинхронно: сразу после создания номера накладной ещё
     * нет, и в заявке остаётся её uuid. Кнопка подтягивает номер, когда он появился.
     */
    private function refreshCdekNumberAction(): Actions\Action
    {
        return Actions\Action::make('refreshCdekNumber')
            ->label('Обновить номер накладной')
            ->icon('heroicon-o-arrow-path')
            ->visible(function () {
                $shipment = $this->shipment(cancelled: false);

                // uuid остаётся в raw_response только пока номера нет: как только
                // накладная получена, tracking_number — это она.
                return $shipment
                    && $shipment->provider === 'cdek'
                    && $shipment->tracking_number === ($shipment->raw_response['uuid'] ?? null);
            })
            ->action(function () {
                $number = app(CdekDispatcher::class)->refreshNumber($this->shipment(cancelled: false));

                Notification::make()
                    ->title($number ? 'Номер накладной: '.$number : 'СДЭК ещё не выдал номер')
                    ->body($number ? null : 'Заявка принята, но накладная пока оформляется. Попробуйте через минуту.')
                    ->status($number ? 'success' : 'warning')
                    ->send();
            });
    }

    private function cancelShipmentAction(): Actions\Action
    {
        return Actions\Action::make('cancelShipment')
            ->label(fn () => 'Отменить заявку в '.(self::CARRIERS[$this->shipment(cancelled: false)?->provider] ?? 'службе доставки'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Заявка будет отменена на стороне перевозчика. Отменить можно, пока посылку не приняли к отправке.')
            ->visible(fn () => (bool) $this->shipment(cancelled: false))
            ->action(function () {
                $shipment = $this->shipment(cancelled: false);

                if (! $shipment) {
                    return;
                }

                $result = $shipment->provider === 'cdek'
                    ? app(CdekClient::class)->deleteOrder((string) ($shipment->raw_response['uuid'] ?? ''))
                    : app(YandexDeliveryClient::class)->cancelRequest((string) $shipment->tracking_number);

                if (! $result['ok']) {
                    Notification::make()
                        ->title('Отменить заявку не удалось')
                        ->body($result['reason'] ?? 'Перевозчик отклонил отмену')
                        ->danger()
                        ->send();

                    return;
                }

                $shipment->update(['status' => 'cancelled']);

                Notification::make()->title('Заявка отменена')->success()->send();
            });
    }

    private function shipment(?bool $cancelled = null): ?Shipment
    {
        $query = $this->record->shipments()->whereNotNull('tracking_number');

        if ($cancelled === false) {
            $query->where('status', '!=', 'cancelled');
        }

        return $query->latest('id')->first();
    }

    private function notifyTelegram(callable $callback): void
    {
        try {
            $callback(app(TelegramNotifier::class));
        } catch (\Throwable $e) {
            Log::error('Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }
}
