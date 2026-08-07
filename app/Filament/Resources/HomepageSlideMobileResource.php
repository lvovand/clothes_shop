<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HomepageSlideMobileResource\Pages;

class HomepageSlideMobileResource extends HomepageSlideResourceBase
{
    protected static ?string $navigationLabel = 'Слайдер — мобильная версия';

    protected static ?string $slug = 'homepage-slides-mobile';

    public static function device(): string
    {
        return 'mobile';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHomepageSlidesMobile::route('/'),
            'create' => Pages\CreateHomepageSlideMobile::route('/create'),
            'edit' => Pages\EditHomepageSlideMobile::route('/{record}/edit'),
        ];
    }
}
