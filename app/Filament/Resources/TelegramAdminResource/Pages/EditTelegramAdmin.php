<?php

namespace App\Filament\Resources\TelegramAdminResource\Pages;

use App\Filament\Resources\TelegramAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTelegramAdmin extends EditRecord
{
    protected static string $resource = TelegramAdminResource::class;

    protected static ?string $title = 'Изменение доступа в приложение';

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
