<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Фотографии';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\FileUpload::make('path')
                    ->label('Изображение')
                    ->image()
                    ->imageEditor()
                    ->directory('products')
                    ->required(),
                Forms\Components\FileUpload::make('thumb_path')
                    ->label('Превью для каталога (необязательно)')
                    ->helperText('Оставьте пустым — превью сделается из фотографии выше автоматически. Загрузите свою картинку, если в каталоге нужен другой кадр; кроп и поворот правятся кнопкой карандаша на загруженном файле.')
                    ->image()
                    ->imageEditor()
                    ->directory('products'),
                Forms\Components\TextInput::make('alt')
                    ->label('Alt-текст'),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('path')->label('Изображение'),
                Tables\Columns\ImageColumn::make('thumb_path')->label('Своё превью'),
                Tables\Columns\TextColumn::make('alt')->label('Alt-текст'),
                Tables\Columns\TextColumn::make('sort_order')->label('Порядок'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
