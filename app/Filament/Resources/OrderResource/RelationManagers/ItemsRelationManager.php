<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Товары в заказе';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('product_title_snapshot')->label('Название товара')->required(),
                Forms\Components\TextInput::make('variant_attrs_snapshot')->label('Цвет/размер'),
                Forms\Components\TextInput::make('qty')->label('Кол-во')->numeric()->required(),
                Forms\Components\TextInput::make('unit_price')->label('Цена за шт.')->numeric()->required(),
                Forms\Components\TextInput::make('line_total')->label('Сумма')->numeric()->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('product_title_snapshot')
            ->emptyStateHeading('Товаров в заказе нет')
            ->emptyStateDescription('Позиции попадают сюда при оформлении заказа.')
            ->columns([
                Tables\Columns\TextColumn::make('product_title_snapshot')->label('Товар'),
                Tables\Columns\TextColumn::make('variant_attrs_snapshot')->label('Цвет/размер'),
                Tables\Columns\TextColumn::make('qty')->label('Кол-во'),
                Tables\Columns\TextColumn::make('unit_price')->label('Цена')->money('rub'),
                Tables\Columns\TextColumn::make('line_total')->label('Сумма')->money('rub'),
                Tables\Columns\TextColumn::make('stock_allocation')
                    ->label('Откуда отгружать')
                    // Позиция может разойтись по двум складам, если на первом не хватило.
                    ->formatStateUsing(function ($state) {
                        $names = Warehouse::pluck('name', 'id');
                        $parts = collect((array) $state)
                            ->map(fn ($qty, $id) => ($names[$id] ?? 'Склад #'.$id).': '.$qty.' шт.')
                            ->implode(', ');

                        return $parts ?: '—';
                    })
                    ->wrap(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
