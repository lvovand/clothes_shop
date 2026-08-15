<?php

namespace App\Filament\Resources\CodeSnippetResource\Pages;

use App\Filament\Resources\CodeSnippetResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCodeSnippets extends ListRecords
{
    protected static string $resource = CodeSnippetResource::class;

    protected static ?string $title = 'Код на сайте';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить код'),
        ];
    }
}
