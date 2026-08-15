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
                // Точка отправления принадлежит складу: доставка считается от того
                // города, где товар лежит, туда же оформляется заявка перевозчику.
                // Пока поля пустые, заказы с этого склада уезжают с основного —
                // того, который стоит первым в очерёдности списания.
                Forms\Components\Section::make('Отгрузка перевозчиками')
                    ->description('Откуда уезжают заказы с этого склада. Если товар лежит только здесь, доставка покупателю считается именно от этих точек. Незаполненные поля означают, что склад сам не отгружает: товар с него довозится на основной склад, и заказ едет оттуда.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('cdek_sender_city_code')
                            ->label('Город отправления (СДЭК)')
                            ->searchable()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search) => collect(
                                app(\App\Services\Cdek\CdekClient::class)->suggestCities($search, 12)
                            )->pluck('label', 'code')->all())
                            ->getOptionLabelUsing(function ($value) {
                                $city = app(\App\Services\Cdek\CdekClient::class)->cityByCode((int) $value);

                                // Справочник может быть недоступен (нет ключей, API
                                // молчит) — показываем хотя бы сохранённый код.
                                return $city['label'] ?? 'Код '.$value;
                            })
                            ->helperText('Начните вводить название — список подтянется из справочника СДЭК. Работает, когда в «Доставка и оплата» заполнены Client ID и Client Secret.'),
                        Forms\Components\Select::make('cdek_shipment_point')
                            ->label('Пункт сдачи посылок (СДЭК)')
                            ->searchable()
                            ->options(fn (Forms\Get $get) => (int) $get('cdek_sender_city_code')
                                ? collect(app(\App\Services\Cdek\CdekClient::class)->receptionPoints((int) $get('cdek_sender_city_code')))
                                    ->pluck('label', 'code')
                                    ->all()
                                : [])
                            ->helperText('Пункт СДЭК, куда магазин привозит заказы с этого склада. Список — по городу слева.'),
                        Forms\Components\TextInput::make('yandex_dropoff_city')
                            ->label('Город сдачи посылок (Яндекс)')
                            ->live(onBlur: true)
                            ->helperText('По нему подбираются точки сдачи Яндекса ниже.'),
                        \App\Forms\Components\YandexDropoffPicker::make('yandex_dropoff_id')
                            ->label('Точка сдачи посылок (Яндекс)')
                            ->cityField('yandex_dropoff_city')
                            ->columnSpanFull()
                            ->helperText('Склад или пункт Яндекса, куда магазин привозит заказы с этого склада. Нажмите «Показать точки на карте» и выберите точку.'),
                    ]),
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
                // Отгружает ли склад сам: без своей точки отправления заказы с него
                // уезжают с основного склада.
                Tables\Columns\IconColumn::make('cdek_shipment_point')
                    ->label('Отгрузка СДЭК')
                    ->boolean()
                    ->state(fn ($record) => $record->shipsVia('cdek') && $record->cdek_shipment_point),
                Tables\Columns\IconColumn::make('yandex_dropoff_id')
                    ->label('Отгрузка Яндексом')
                    ->boolean()
                    ->state(fn ($record) => $record->shipsVia('yandex')),
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
