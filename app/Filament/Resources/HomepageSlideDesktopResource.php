<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomepageSlideDesktopResource\Pages;

class HomepageSlideDesktopResource extends HomepageSlideResourceBase
{
    protected static ?string $navigationLabel = 'Слайдер — десктоп';

    protected static ?string $slug = 'homepage-slides-desktop';

    public static function device(): string
    {
        return 'desktop';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomepageSlidesDesktop::route('/'),
            'create' => Pages\CreateHomepageSlideDesktop::route('/create'),
            'edit' => Pages\EditHomepageSlideDesktop::route('/{record}/edit'),
        ];
    }
}
