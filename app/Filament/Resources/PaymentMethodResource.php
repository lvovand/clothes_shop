<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentMethodResource\Pages;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    protected static ?string $model = PaymentMethod::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Способы оплаты';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $modelLabel = 'способ оплаты';

    protected static ?string $pluralModelLabel = 'способы оплаты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('key')
                    ->label('Платёжный провайдер')
                    ->options([
                        'tbank' => 'Т-Банк',
                        'yandex_pay' => 'Яндекс Пэй (картой)',
                        'yandex_split' => 'Яндекс Сплит (оплата частями)',
                    ])
                    ->helperText('Список ограничен провайдерами, для которых есть готовая интеграция в коде. Новый провайдер добавляется разработчиком, после чего появится здесь. Ключи доступа задаются в «Настройки → Доставка и оплата».')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('name')
                    ->label('Название для покупателя')
                    ->required()
                    ->helperText('Например: "Оплата картой онлайн (Т-Банк)"'),
                Forms\Components\Toggle::make('is_active')->label('Доступен для оплаты')->default(true),
                Forms\Components\TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Название'),
                Tables\Columns\TextColumn::make('key')->label('Провайдер'),
                Tables\Columns\IconColumn::make('is_active')->label('Доступен')->boolean(),
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
            'index' => Pages\ListPaymentMethods::route('/'),
            'create' => Pages\CreatePaymentMethod::route('/create'),
            'edit' => Pages\EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
