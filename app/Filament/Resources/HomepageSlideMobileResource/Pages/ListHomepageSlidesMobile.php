<?php

namespace App\Filament\Resources\HomepageSlideMobileResource\Pages;

use App\Filament\Resources\HomepageSlideMobileResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHomepageSlidesMobile extends ListRecords
{
    protected static string $resource = HomepageSlideMobileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
