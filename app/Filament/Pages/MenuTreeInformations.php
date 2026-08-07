<?php

namespace App\Filament\Pages;

class MenuTreeInformations extends MenuTreeBase
{
    protected static string $menuKey = 'informations';

    protected static ?string $navigationLabel = 'Футер — сервис';

    protected static ?string $title = 'Футер — сервис';

    protected static ?string $slug = 'menu-service';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?int $navigationSort = 3;
}
