<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers;
use App\Models\Order;
use App\Models\PaymentMethod;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    // Подписи статусов — в модели Order: их показывает и админка, и
    // мини-приложение бота (App\Http\Controllers\Telegram\OrdersController).
    private const STATUS_LABELS = Order::STATUS_LABELS;

    private const PAYMENT_STATUS_LABELS = Order::PAYMENT_STATUS_LABELS;

    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationLabel = 'Заказы';

    protected static ?string $navigationGroup = 'Заказы';

    protected static ?string $modelLabel = 'заказ';

    protected static ?string $pluralModelLabel = 'заказы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Заказ')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('order_number')->label('Номер заказа')->required(),
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(self::STATUS_LABELS)
                            ->required(),
                        Forms\Components\TextInput::make('customer_name')->label('Имя клиента'),
                        Forms\Components\TextInput::make('customer_phone')->label('Телефон')->tel(),
                        Forms\Components\TextInput::make('customer_email')->label('Email')->email(),
                        Forms\Components\Select::make('shipping_method_id')
                            ->label('Способ доставки')
                            ->relationship('shippingMethod', 'title'),
                    ]),
                Forms\Components\Section::make('Оплата и суммы')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('payment_method')
                            ->label('Способ оплаты')
                            ->options(PaymentMethod::LABELS),
                        Forms\Components\Select::make('payment_status')
                            ->label('Статус оплаты')
                            ->options(self::PAYMENT_STATUS_LABELS)
                            ->required(),
                        Forms\Components\TextInput::make('subtotal')->label('Сумма товаров')->numeric()->required(),
                        Forms\Components\TextInput::make('shipping_cost')->label('Стоимость доставки')->numeric()->default(0),
                        Forms\Components\TextInput::make('discount_total')->label('Скидка по промокоду, ₽')->numeric()->default(0),
                        Forms\Components\TextInput::make('coupon_code')->label('Промокод'),
                        Forms\Components\TextInput::make('gift_certificate_code')->label('Код подарочного сертификата'),
                        Forms\Components\TextInput::make('gift_certificate_used')
                            ->label('Списано с сертификата, ₽')
                            ->numeric()
                            ->default(0),
                        Forms\Components\TextInput::make('total')->label('Итого')->numeric()->required(),
                    ]),
                Forms\Components\Section::make('Доставка и комментарий')
                    ->schema([
                        // Заявка в Яндекс Доставке: номер и состояние. Создаётся сама
                        // после оплаты, кнопки создания/отмены — в шапке заказа.
                        Forms\Components\Placeholder::make('yandex_shipment')
                            ->label('Заявка в Яндекс Доставке')
                            ->columnSpanFull()
                            ->visible(fn (?\App\Models\Order $record) => $record?->shippingMethod?->provider() === 'yandex')
                            ->content(function (?\App\Models\Order $record) {
                                $shipment = $record?->shipments()
                                    ->where('provider', 'yandex')
                                    ->whereNotNull('tracking_number')
                                    ->latest('id')
                                    ->first();

                                if (! $shipment) {
                                    return 'Заявка ещё не создана.';
                                }

                                $status = match ($shipment->status) {
                                    'created' => 'создана',
                                    'cancelled' => 'отменена',
                                    default => $shipment->status,
                                };

                                return 'Номер: '.$shipment->tracking_number.' — '.$status
                                    .($shipment->pvz_address ? ' (ПВЗ: '.$shipment->pvz_address.')' : '');
                            }),
                        // Поле хранится массивом (cast 'array'), а Textarea показывает
                        // строку — без преобразования на экране было буквально
                        // «[object Object]» вместо самого адреса.
                        Forms\Components\Textarea::make('shipping_address')
                            ->label('Адрес доставки (JSON)')
                            ->columnSpanFull()
                            ->afterStateHydrated(fn (Forms\Components\Textarea $component, $state) => $component->state(
                                is_array($state) ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : $state
                            ))
                            ->dehydrateStateUsing(fn ($state) => is_string($state) ? (json_decode($state, true) ?? []) : $state),
                        Forms\Components\Textarea::make('comment')->label('Комментарий клиента')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('№ заказа')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid', 'completed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('customer_name')->label('Клиент')->searchable(),
                Tables\Columns\TextColumn::make('customer_phone')->label('Телефон')->searchable(),
                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::PAYMENT_STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('total')->label('Итого')->money('rub')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(self::STATUS_LABELS),
                Tables\Filters\SelectFilter::make('payment_status')->label('Оплата')->options(self::PAYMENT_STATUS_LABELS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Просмотр'),
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
        return [
            RelationManagers\ItemsRelationManager::class,
            RelationManagers\PaymentsRelationManager::class,
            RelationManagers\ShipmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'view' => Pages\ViewOrder::route('/{record}'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
