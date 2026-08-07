<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\AttributeValue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Варианты (цвет/размер)';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sku')
                    ->label('Артикул (SKU)'),
                Forms\Components\TextInput::make('regular_price')
                    ->label('Цена, ₽')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('sale_price')
                    ->label('Цена по скидке, ₽')
                    ->numeric()
                    ->helperText('Заполни, если товар со скидкой — метка "SALE" на витрине появится сама, отдельно включать её не нужно. Оставь пустым, если скидки нет.'),
                Forms\Components\TextInput::make('stock_qty')
                    ->label('Остаток, шт.')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Forms\Components\Select::make('image_id')
                    ->label('Фото варианта')
                    ->relationship('image', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->alt ?: basename($record->path))
                    ->searchable(),
                Forms\Components\Select::make('attributeValues')
                    ->label('Цвет / размер')
                    ->relationship('attributeValues', 'label')
                    ->multiple()
                    ->options(AttributeValue::with('attribute')->get()->mapWithKeys(
                        fn (AttributeValue $v) => [$v->id => "{$v->attribute->name}: {$v->label}"]
                    ))
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                Tables\Columns\TextColumn::make('sku')->label('Артикул'),
                Tables\Columns\TextColumn::make('attributeValues.label')->label('Цвет/размер')->badge(),
                Tables\Columns\TextColumn::make('regular_price')->label('Цена')->money('rub'),
                Tables\Columns\TextColumn::make('sale_price')->label('Цена по скидке')->money('rub'),
                Tables\Columns\TextColumn::make('stock_qty')->label('Остаток'),
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
