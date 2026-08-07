<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Платежи (T-Bank)';

    // Payments are created by the payment webhook, not by hand — read-only here.
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('provider')->label('Провайдер')->disabled(),
                Forms\Components\TextInput::make('provider_payment_id')->label('ID платежа в системе провайдера')->disabled(),
                Forms\Components\TextInput::make('amount')->label('Сумма')->numeric()->disabled(),
                Forms\Components\TextInput::make('status')->label('Статус')->disabled(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('provider_payment_id')
            ->columns([
                Tables\Columns\TextColumn::make('provider')->label('Провайдер'),
                Tables\Columns\TextColumn::make('provider_payment_id')->label('ID платежа'),
                Tables\Columns\TextColumn::make('amount')->label('Сумма')->money('rub'),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('created_at')->label('Дата')->dateTime(),
            ])
            ->headerActions([])
            ->actions([]);
    }
}
