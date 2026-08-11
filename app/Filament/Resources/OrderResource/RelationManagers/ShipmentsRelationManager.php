<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ShipmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Доставка';

    private const PROVIDERS = [
        'cdek' => 'СДЭК',
        'yandex' => 'Яндекс',
    ];

    /** В заголовке — тот перевозчик, которого выбрали в этом заказе. */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        $provider = self::PROVIDERS[$ownerRecord->shippingMethod?->provider()] ?? null;

        return $provider ? 'Доставка ('.$provider.')' : 'Доставка';
    }

    /**
     * У самовывоза перевозчика нет — отправлений по такому заказу не бывает,
     * и вкладка была бы всегда пустой. Уже созданную запись всё равно покажем.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->shippingMethod?->provider() !== 'none'
            || $ownerRecord->shipments()->exists();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('provider')
                    ->label('Провайдер')
                    // Перевозчик берётся из способа доставки заказа: раньше здесь
                    // жёстко стоял cdek, и у заказа Яндексом подставлялся чужой.
                    ->default(fn () => $this->getOwnerRecord()->shippingMethod?->provider()),
                Forms\Components\TextInput::make('tracking_number')->label('Трек-номер'),
                Forms\Components\TextInput::make('pvz_code')->label('Код ПВЗ'),
                Forms\Components\Textarea::make('pvz_address')->label('Адрес ПВЗ')->columnSpanFull(),
                Forms\Components\TextInput::make('status')->label('Статус'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tracking_number')
            ->emptyStateHeading('Заявка на доставку ещё не создана')
            ->emptyStateDescription('Для Яндекс Доставки заявка создаётся сама после оплаты — или кнопкой «Создать заявку» вверху страницы. Для остальных перевозчиков отправление можно завести здесь вручную.')
            ->columns([
                Tables\Columns\TextColumn::make('provider')->label('Провайдер'),
                Tables\Columns\TextColumn::make('tracking_number')->label('Трек-номер'),
                Tables\Columns\TextColumn::make('pvz_code')->label('ПВЗ'),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }
}
