<?php

namespace App\Filament\Resources\AttributeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    protected static ?string $title = 'Значения (цвета/размеры)';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('value')
                    ->label('Slug (латиницей)')
                    ->helperText('Например: black, cream — латиницей, без кириллицы')
                    ->required(),
                Forms\Components\TextInput::make('label')
                    ->label('Отображаемое название')
                    ->helperText('Например: Чёрный')
                    ->required(),
                Forms\Components\ColorPicker::make('swatch_hex')
                    ->label('Цвет свотча (если нужен кружок цвета)'),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Название'),
                Tables\Columns\TextColumn::make('value')->label('Slug'),
                Tables\Columns\ColorColumn::make('swatch_hex')->label('Цвет'),
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
