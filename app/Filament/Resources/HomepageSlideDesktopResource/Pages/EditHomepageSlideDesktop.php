<?php

namespace App\Filament\Resources\HomepageSlideDesktopResource\Pages;

use App\Filament\Resources\HomepageSlideDesktopResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHomepageSlideDesktop extends EditRecord
{
    protected static string $resource = HomepageSlideDesktopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
