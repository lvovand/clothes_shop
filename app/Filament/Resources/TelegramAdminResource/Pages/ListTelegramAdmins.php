<?php

namespace App\Filament\Resources\TelegramAdminResource\Pages;

use App\Filament\Resources\TelegramAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelegramAdmins extends ListRecords
{
    protected static string $resource = TelegramAdminResource::class;

    protected static ?string $title = 'Доступ в Telegram-приложение';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить никнейм'),
        ];
    }
}
