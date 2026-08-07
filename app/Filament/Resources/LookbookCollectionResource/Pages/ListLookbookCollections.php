<?php

namespace App\Filament\Resources\LookbookCollectionResource\Pages;

use App\Filament\Resources\LookbookCollectionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLookbookCollections extends ListRecords
{
    protected static string $resource = LookbookCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
