<?php

namespace App\Filament\Resources\HomepageSlideMobileResource\Pages;

use App\Filament\Resources\HomepageSlideMobileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHomepageSlideMobile extends EditRecord
{
    protected static string $resource = HomepageSlideMobileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
