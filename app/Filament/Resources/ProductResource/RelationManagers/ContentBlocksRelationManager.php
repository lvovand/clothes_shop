<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ContentBlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'contentBlocks';

    protected static ?string $title = 'Описание и уход / параметры';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('key')
                    ->label('Тип блока')
                    ->options([
                        'description_care' => 'Описание и уход',
                        'fit' => 'Параметры изделия',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->label('Заголовок')
                    ->required(),
                Forms\Components\RichEditor::make('body')
                    ->label('Текст')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->emptyStateHeading('Блоков описания пока нет')
            ->emptyStateDescription('Нажмите «Создать», чтобы добавить «Описание и уход» или «Параметры изделия».')
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Заголовок'),
                Tables\Columns\TextColumn::make('key')->label('Тип'),
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
