<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CouponResource\Pages;
use App\Models\Coupon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Промокоды';

    protected static ?string $navigationGroup = 'Заказы';

    protected static ?string $modelLabel = 'промокод';

    protected static ?string $pluralModelLabel = 'промокоды';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Промокод')
                    ->helperText('То, что покупатель вводит в корзине. Регистр не важен.')
                    ->required()
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('type')
                    ->label('Тип скидки')
                    ->options([
                        'percent' => 'Процент от суммы товаров',
                        'fixed' => 'Фиксированная сумма, ₽',
                    ])
                    ->default('percent')
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('value')
                    ->label(fn (Forms\Get $get) => $get('type') === 'fixed' ? 'Размер скидки, ₽' : 'Размер скидки, %')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('min_subtotal')
                    ->label('Минимальная сумма заказа, ₽')
                    ->helperText('Пусто — без ограничения')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Действует с')
                    ->seconds(false),
                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('Действует до')
                    ->seconds(false),
                Forms\Components\TextInput::make('usage_limit')
                    ->label('Лимит применений')
                    ->helperText('Пусто — без лимита')
                    ->numeric(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Промокод')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('value')
                    ->label('Скидка')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->type === 'percent'
                        ? rtrim(rtrim(number_format((float) $state, 2, ',', ' '), '0'), ',').' %'
                        : number_format((float) $state, 0, ',', ' ').' ₽'),
                Tables\Columns\TextColumn::make('min_subtotal')->label('От суммы')->money('rub')->placeholder('—'),
                Tables\Columns\TextColumn::make('used_count')->label('Применён')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->usage_limit
                        ? $state.' из '.$record->usage_limit
                        : (string) $state),
                Tables\Columns\TextColumn::make('expires_at')->label('До')->dateTime('d.m.Y H:i')->placeholder('без срока'),
                Tables\Columns\IconColumn::make('is_active')->label('Активен')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Активность'),
            ])
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
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
