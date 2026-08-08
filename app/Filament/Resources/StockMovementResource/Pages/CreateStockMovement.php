<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockMovement extends CreateRecord
{
    protected static string $resource = StockMovementResource::class;

    protected static ?string $title = 'Приход / списание товара';

    /**
     * Запись журнала не создаётся сама по себе: остаток и строку журнала пишет
     * StockService, иначе число на складе и история разошлись бы.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return StockMovementResource::applyManual($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
