<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Склады';

    protected static ?string $navigationGroup = 'Склад';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'склад';

    protected static ?string $pluralModelLabel = 'склады';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->helperText('Как склад называется в админке: «Москва», «Оренбург».'),
                Forms\Components\TextInput::make('code')
                    ->label('Код')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('Латиницей, без пробелов. Используется в коде, менять после создания не нужно.'),
                Forms\Components\TextInput::make('city')
                    ->label('Город'),
                Forms\Components\Toggle::make('allows_pickup')
                    ->label('С этого склада возможен самовывоз')
                    ->helperText('Покупатель может забрать заказ сам. Если товара на таком складе нет, самовывоз на оформлении становится недоступен.'),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Очерёдность списания при доставке')
                    ->numeric()
                    ->default(0)
                    ->required()
                    ->helperText('Чем меньше число, тем раньше товар берётся с этого склада. Сейчас: Оренбург 0 (берём первым), Москва 10 (когда в Оренбурге не хватило).'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Склад используется')
                    ->default(true)
                    ->helperText('Выключенный склад не участвует ни в продаже, ни в остатках на витрине.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название'),
                Tables\Columns\TextColumn::make('city')->label('Город'),
                Tables\Columns\IconColumn::make('allows_pickup')->label('Самовывоз')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('Очерёдность'),
                Tables\Columns\TextColumn::make('stocks_sum_qty')
                    ->label('Всего товара, шт.')
                    ->sum('stocks', 'qty'),
                Tables\Columns\IconColumn::make('is_active')->label('Используется')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouses::route('/'),
            'create' => Pages\CreateWarehouse::route('/create'),
            'edit' => Pages\EditWarehouse::route('/{record}/edit'),
        ];
    }
}
