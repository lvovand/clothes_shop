<?php

namespace App\Filament\Resources\LookbookCollectionResource\Pages;

use App\Filament\Resources\LookbookCollectionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLookbookCollection extends EditRecord
{
    protected static string $resource = LookbookCollectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
