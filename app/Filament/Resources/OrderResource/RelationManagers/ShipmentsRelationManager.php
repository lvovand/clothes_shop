<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Доставка (CDEK)';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('provider')->label('Провайдер')->default('cdek'),
                Forms\Components\TextInput::make('tracking_number')->label('Трек-номер'),
                Forms\Components\TextInput::make('pvz_code')->label('Код ПВЗ'),
                Forms\Components\Textarea::make('pvz_address')->label('Адрес ПВЗ')->columnSpanFull(),
                Forms\Components\TextInput::make('status')->label('Статус'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tracking_number')
            ->columns([
                Tables\Columns\TextColumn::make('provider')->label('Провайдер'),
                Tables\Columns\TextColumn::make('tracking_number')->label('Трек-номер'),
                Tables\Columns\TextColumn::make('pvz_code')->label('ПВЗ'),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }
}
