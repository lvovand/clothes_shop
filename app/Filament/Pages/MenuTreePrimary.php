<?php

namespace App\Filament\Pages;

class MenuTreePrimary extends MenuTreeBase
{
    protected static string $menuKey = 'primary';

    protected static ?string $navigationLabel = 'Меню в шапке';

    protected static ?string $title = 'Меню в шапке';

    protected static ?string $slug = 'menu-header';

    protected static ?string $navigationIcon = 'heroicon-o-bars-3';

    protected static ?int $navigationSort = 1;
}
