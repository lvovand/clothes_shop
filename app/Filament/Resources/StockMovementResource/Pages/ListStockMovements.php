<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    // Иначе Filament капитализирует каждое слово: «Движения Товара».
    protected static ?string $title = 'Движения товара';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Приход / списание'),
        ];
    }
}
