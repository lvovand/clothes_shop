<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShippingMethodResource\Pages;
use App\Models\ShippingMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ShippingMethodResource extends Resource
{
    protected static ?string $model = ShippingMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Способы доставки';

    protected static ?string $navigationGroup = 'Заказы';

    protected static ?string $modelLabel = 'способ доставки';

    protected static ?string $pluralModelLabel = 'способы доставки';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Код (техническое имя)')
                    ->helperText('Например: pickup, courier_moscow, cdek_pvz, cdek_door')
                    ->required(),
                Forms\Components\TextInput::make('title')
                    ->label('Название для покупателя')
                    ->required(),
                Forms\Components\Toggle::make('is_enabled')
                    ->label('Включён'),
                // Эти два поля определяют поведение способа на оформлении. Раньше они
                // лежали только внутри JSON-поля config и не были частью формы —
                // из-за этого любое сохранение способа в админке их стирало, и ПВЗ
                // Яндекса превращался в обычную курьерскую доставку с полями адреса.
                Forms\Components\Select::make('config.provider')
                    ->label('Кто везёт')
                    ->options([
                        'none' => 'Свои силы (самовывоз, свой курьер)',
                        'cdek' => 'СДЭК',
                        'yandex' => 'Яндекс Доставка',
                    ])
                    ->default('none')
                    ->selectablePlaceholder(false)
                    ->helperText('От этого зависит, у кого запрашивается стоимость доставки.'),
                Forms\Components\Select::make('config.kind')
                    ->label('Тип способа')
                    ->options([
                        'pickup' => 'Самовывоз из нашего пункта',
                        'pvz' => 'Пункт выдачи (покупатель выбирает пункт)',
                        'door' => 'Курьер до двери (покупатель вводит адрес)',
                    ])
                    ->default('door')
                    ->selectablePlaceholder(false)
                    ->helperText('Определяет, что покупатель заполняет на оформлении: адрес или выбор пункта выдачи.'),
                Forms\Components\Toggle::make('cod_allowed')
                    ->label('Разрешена оплата при получении')
                    ->helperText('Например: только для самовывоза'),
                Forms\Components\TextInput::make('flat_cost')
                    ->label('Фиксированная стоимость, ₽')
                    ->numeric(),
                Forms\Components\TextInput::make('free_from_amount')
                    ->label('Бесплатно от суммы, ₽')
                    ->numeric(),
                // Раньше эти два значения правились только вручную в JSON-поле.
                // Адрес самовывоза покупатель видит прямо на странице оформления,
                // поэтому у него отдельное человеческое поле.
                Forms\Components\TextInput::make('config.address')
                    ->label('Адрес пункта самовывоза')
                    ->helperText('Показывается покупателю при выборе самовывоза. Только для способа «Самовывоз».')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('config.hours')
                    ->label('Часы работы пункта самовывоза')
                    ->helperText('Например: Ежедневно 11.00 - 20.00. Показывается под адресом. Только для способа «Самовывоз».'),
                Forms\Components\TextInput::make('config.phone')
                    ->label('Телефон пункта самовывоза')
                    ->helperText('Если пусто — берётся телефон из «Настройки → Контакты».'),
                Forms\Components\TextInput::make('config.tariff_code')
                    ->label('Код тарифа CDEK')
                    ->helperText('Только для способов CDEK: 136 — пункт выдачи, 137 — курьер до двери.')
                    ->numeric(),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('code')->label('Код')->searchable(),
                Tables\Columns\TextColumn::make('provider_label')
                    ->label('Кто везёт')
                    ->state(fn (\App\Models\ShippingMethod $record) => match ($record->provider()) {
                        'cdek' => 'СДЭК',
                        'yandex' => 'Яндекс Доставка',
                        default => 'свои силы',
                    }),
                Tables\Columns\TextColumn::make('kind_label')
                    ->label('Тип')
                    ->state(fn (\App\Models\ShippingMethod $record) => match ($record->kind()) {
                        'pickup' => 'самовывоз',
                        'pvz' => 'пункт выдачи',
                        default => 'курьер',
                    }),
                Tables\Columns\IconColumn::make('is_enabled')->label('Включён')->boolean(),
                Tables\Columns\IconColumn::make('cod_allowed')->label('Наличные при получении')->boolean(),
                Tables\Columns\TextColumn::make('flat_cost')->label('Стоимость')->money('rub'),
                Tables\Columns\TextColumn::make('free_from_amount')->label('Бесплатно от')->money('rub'),
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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingMethods::route('/'),
            'create' => Pages\CreateShippingMethod::route('/create'),
            'edit' => Pages\EditShippingMethod::route('/{record}/edit'),
        ];
    }
}
