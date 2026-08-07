<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Shipment;
use App\Services\Telegram\TelegramNotifier;
use App\Services\YandexDelivery\YandexDeliveryClient;
use App\Services\YandexDelivery\YandexDeliveryDispatcher;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->createYandexShipmentAction(),
            $this->cancelYandexShipmentAction(),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Заявка обычно создаётся сама после оплаты (настройка «Доставка и оплата» →
     * «Создавать заявку на доставку автоматически»). Кнопка нужна для случаев, когда
     * автосоздание выключено или один раз не сработало.
     */
    private function createYandexShipmentAction(): Actions\Action
    {
        return Actions\Action::make('createYandexShipment')
            ->label('Создать заявку в Яндекс Доставке')
            ->icon('heroicon-o-truck')
            ->requiresConfirmation()
            ->modalDescription('Заявка будет создана в Яндекс Доставке по данным этого заказа. Стоимость доставки берётся самая дешёвая из предложенных Яндексом.')
            ->visible(fn () => $this->record->shippingMethod?->provider() === 'yandex' && ! $this->yandexShipment())
            ->action(function () {
                $result = app(YandexDeliveryDispatcher::class)->dispatch($this->record, force: true);

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

    private function cancelYandexShipmentAction(): Actions\Action
    {
        return Actions\Action::make('cancelYandexShipment')
            ->label('Отменить заявку в Яндекс Доставке')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Заявка будет отменена на стороне Яндекса. Отменить можно, пока посылку не приняли в точке сдачи.')
            ->visible(fn () => (bool) $this->yandexShipment(cancelled: false))
            ->action(function () {
                $shipment = $this->yandexShipment(cancelled: false);

                if (! $shipment) {
                    return;
                }

                $result = app(YandexDeliveryClient::class)->cancelRequest((string) $shipment->tracking_number);

                if (! $result['ok']) {
                    Notification::make()
                        ->title('Отменить заявку не удалось')
                        ->body($result['reason'] ?? 'Яндекс отклонил отмену')
                        ->danger()
                        ->send();

                    return;
                }

                $shipment->update(['status' => 'cancelled']);

                Notification::make()->title('Заявка отменена')->success()->send();
            });
    }

    private function yandexShipment(?bool $cancelled = null): ?Shipment
    {
        $query = $this->record->shipments()
            ->where('provider', 'yandex')
            ->whereNotNull('tracking_number');

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
            Log::warning('Telegram notify failed', ['error' => $e->getMessage()]);
        }
    }
}
