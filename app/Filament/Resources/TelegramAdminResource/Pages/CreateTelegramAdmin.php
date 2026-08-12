<?php

namespace App\Filament\Resources\TelegramAdminResource\Pages;

use App\Filament\Resources\TelegramAdminResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramAdmin extends CreateRecord
{
    protected static string $resource = TelegramAdminResource::class;

    protected static ?string $title = 'Новый доступ в приложение';
}
