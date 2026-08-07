<?php

namespace App\Filament\Resources\GiftCertificateResource\Pages;

use App\Filament\Resources\GiftCertificateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGiftCertificates extends ListRecords
{
    protected static string $resource = GiftCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
