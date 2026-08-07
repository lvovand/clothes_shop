<?php

namespace App\Filament\Pages;

class MenuTreeFooter extends MenuTreeBase
{
    protected static string $menuKey = 'footer';

    protected static ?string $navigationLabel = 'Футер — покупателям';

    protected static ?string $title = 'Футер — покупателям';

    protected static ?string $slug = 'menu-footer';

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?int $navigationSort = 2;
}
