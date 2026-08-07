<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LookbookCollectionResource\Pages;
use App\Filament\Resources\LookbookCollectionResource\RelationManagers\PhotosRelationManager;
use App\Models\LookbookCollection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LookbookCollectionResource extends Resource
{
    protected static ?string $model = LookbookCollection::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Lookbook';

    protected static ?string $navigationGroup = 'Контент';

    protected static ?string $modelLabel = 'коллекция';

    protected static ?string $pluralModelLabel = 'lookbook: коллекции';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')->label('Название (напр. SS26/01)')->required(),
                Forms\Components\Toggle::make('is_active')->label('Показывать на сайте')->default(true),
                Forms\Components\TextInput::make('sort_order')->label('Порядок вкладки')->numeric()->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название'),
                Tables\Columns\TextColumn::make('photos_count')->label('Фото')->counts('photos'),
                Tables\Columns\IconColumn::make('is_active')->label('Активна')->boolean(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make()->label('Изменить'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()->label('Удалить'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLookbookCollections::route('/'),
            'create' => Pages\CreateLookbookCollection::route('/create'),
            'edit' => Pages\EditLookbookCollection::route('/{record}/edit'),
        ];
    }
}
