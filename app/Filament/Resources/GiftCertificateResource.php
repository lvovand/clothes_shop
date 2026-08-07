<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GiftCertificateResource\Pages;
use App\Models\GiftCertificate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GiftCertificateResource extends Resource
{
    protected static ?string $model = GiftCertificate::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Подарочные сертификаты';

    protected static ?string $navigationGroup = 'Заказы';

    protected static ?string $modelLabel = 'сертификат';

    protected static ?string $pluralModelLabel = 'подарочные сертификаты';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Сертификат')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('code')->label('Код сертификата')->required(),
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options([
                                'pending' => 'Ожидает оплаты',
                                'active' => 'Активен',
                                'redeemed' => 'Использован',
                                'expired' => 'Истёк',
                                'failed' => 'Ошибка оплаты',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('initial_amount')->label('Номинал, ₽')->numeric()->required(),
                        Forms\Components\TextInput::make('remaining_balance')->label('Остаток, ₽')->numeric()->required(),
                    ]),
                Forms\Components\Section::make('Получатель')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('recipient_name')->label('Имя получателя'),
                        Forms\Components\TextInput::make('recipient_email')->label('Email получателя')->email(),
                        Forms\Components\Textarea::make('message')->label('Поздравительное сообщение')->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Покупатель')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('buyer_name')->label('Имя покупателя'),
                        Forms\Components\TextInput::make('buyer_email')->label('Email покупателя')->email(),
                        Forms\Components\TextInput::make('buyer_phone')->label('Телефон покупателя')->tel(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Код')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('initial_amount')->label('Номинал')->money('rub'),
                Tables\Columns\TextColumn::make('remaining_balance')->label('Остаток')->money('rub'),
                Tables\Columns\TextColumn::make('buyer_name')->label('Покупатель')->searchable(),
                Tables\Columns\TextColumn::make('recipient_name')->label('Получатель')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Создан')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options([
                    'pending' => 'Ожидает оплаты', 'active' => 'Активен', 'redeemed' => 'Использован', 'expired' => 'Истёк', 'failed' => 'Ошибка оплаты',
                ]),
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
            'index' => Pages\ListGiftCertificates::route('/'),
            'create' => Pages\CreateGiftCertificate::route('/create'),
            'edit' => Pages\EditGiftCertificate::route('/{record}/edit'),
        ];
    }
}
