<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Services\Shipping\ShipmentActions;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

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

    /**
     * Сами действия живут в ShipmentActions: те же кнопки есть в мини-приложении
     * бота, и логика создания/отмены заявки должна быть одна на всех.
     */
    private function actions(): ShipmentActions
    {
        return app(ShipmentActions::class);
    }

    private function carrierName(): string
    {
        return $this->actions()->carrierName($this->record) ?? 'службе доставки';
    }

    /**
     * Заявка обычно создаётся сама после оплаты (настройка «Доставка и оплата» →
     * «Создавать заявку на доставку автоматически»). Кнопка нужна для случаев, когда
     * автосоздание выключено или один раз не сработало.
     */
    private function createShipmentAction(): Actions\Action
    {
        return Actions\Action::make('createShipment')
            ->label(fn () => 'Создать заявку в '.$this->carrierName())
            ->icon('heroicon-o-truck')
            ->requiresConfirmation()
            ->modalDescription(fn () => $this->record->shippingMethod?->provider() === 'cdek'
                ? 'Заявка будет создана в СДЭК по данным этого заказа: тариф берётся из способа доставки, посылка отправляется из пункта сдачи, указанного в настройках.'
                : 'Заявка будет создана в Яндекс Доставке по данным этого заказа. Стоимость доставки берётся самая дешёвая из предложенных Яндексом.')
            ->visible(fn () => $this->actions()->canCreate($this->record))
            ->action(function () {
                $result = $this->actions()->create($this->record);

                Notification::make()
                    ->title($result['ok'] ? 'Заявка создана' : 'Заявку создать не удалось')
                    ->body($result['message'])
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->send();
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
            ->visible(fn () => $this->actions()->canRefreshNumber($this->record))
            ->action(function () {
                $result = $this->actions()->refreshNumber($this->record);

                Notification::make()
                    ->title($result['ok'] ? 'Номер получен' : 'СДЭК ещё не выдал номер')
                    ->body($result['message'])
                    ->status($result['ok'] ? 'success' : 'warning')
                    ->send();
            });
    }

    private function cancelShipmentAction(): Actions\Action
    {
        return Actions\Action::make('cancelShipment')
            ->label(fn () => 'Отменить заявку в '.$this->carrierName())
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Заявка будет отменена на стороне перевозчика. Отменить можно, пока посылку не приняли к отправке.')
            ->visible(fn () => $this->actions()->canCancel($this->record))
            ->action(function () {
                $result = $this->actions()->cancel($this->record);

                Notification::make()
                    ->title($result['ok'] ? 'Заявка отменена' : 'Отменить заявку не удалось')
                    ->body($result['ok'] ? null : $result['message'])
                    ->status($result['ok'] ? 'success' : 'danger')
                    ->send();
            });
    }
}
