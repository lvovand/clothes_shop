<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Models\AttributeValue;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Варианты (цвет/размер)';

    /** Префикс полей остатка: stock_wh_<id склада>. */
    private const STOCK_FIELD_PREFIX = 'stock_wh_';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('sku')
                    ->label('Артикул (SKU)'),
                Forms\Components\TextInput::make('regular_price')
                    ->label('Цена, ₽')
                    ->numeric()
                    ->required(),
                Forms\Components\TextInput::make('sale_price')
                    ->label('Цена по скидке, ₽')
                    ->numeric()
                    ->helperText('Заполни, если товар со скидкой — метка "SALE" на витрине появится сама, отдельно включать её не нужно. Оставь пустым, если скидки нет.'),
                Forms\Components\Fieldset::make('Остаток по складам')
                    ->columns(2)
                    ->schema(
                        // Поля рисуются по списку складов из раздела «Склад → Склады»:
                        // добавится третий склад — поле появится само, править код не нужно.
                        Warehouse::active()->get()->map(fn (Warehouse $warehouse) => Forms\Components\TextInput::make(self::STOCK_FIELD_PREFIX.$warehouse->id)
                            ->label($warehouse->name.', шт.')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->helperText($warehouse->allows_pickup
                                ? 'С этого склада возможен самовывоз.'
                                : 'Отправка только доставкой (СДЭК/Яндекс).')
                        )->all()
                    ),
                Forms\Components\Select::make('image_id')
                    ->label('Фото варианта')
                    ->relationship('image', 'id')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->alt ?: basename($record->path))
                    ->searchable(),
                Forms\Components\Select::make('attributeValues')
                    ->label('Цвет / размер')
                    ->relationship('attributeValues', 'label')
                    ->multiple()
                    ->options(AttributeValue::with('attribute')->get()->mapWithKeys(
                        fn (AttributeValue $v) => [$v->id => "{$v->attribute->name}: {$v->label}"]
                    ))
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        $warehouses = Warehouse::active()->get();

        return $table
            ->recordTitleAttribute('sku')
            ->emptyStateHeading('Вариантов пока нет')
            ->emptyStateDescription('Нажмите «Создать», чтобы добавить сочетание цвета и размера со своей ценой и остатком.')
            ->columns([
                Tables\Columns\TextColumn::make('sku')->label('Артикул'),
                Tables\Columns\TextColumn::make('attributeValues.label')->label('Цвет/размер')->badge(),
                Tables\Columns\TextColumn::make('regular_price')->label('Цена')->money('rub'),
                Tables\Columns\TextColumn::make('sale_price')->label('Цена по скидке')->money('rub'),
                Tables\Columns\TextColumn::make('stock_qty')
                    ->label('Остаток')
                    // Общее число плюс разбивка по складам: «5 (Оренбург 3, Москва 2)» —
                    // иначе не видно, доступен ли товар самовывозом.
                    ->formatStateUsing(function ($state, Variant $record) use ($warehouses) {
                        $record->loadMissing('stocks');
                        $parts = $warehouses
                            ->map(fn (Warehouse $w) => $w->name.' '.$record->stockAt($w->id))
                            ->implode(', ');

                        return $parts ? "{$state} ({$parts})" : (string) $state;
                    }),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->using(function (array $data, RelationManager $livewire): Variant {
                        // Остатки — не колонки варианта: создаём вариант без них,
                        // потом проводим приход через склад (с записью в журнал).
                        $variant = $livewire->getRelationship()->create(self::withoutStockFields($data));
                        self::applyStock($variant, $data);

                        return $variant;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateRecordDataUsing(function (array $data, Variant $record): array {
                        foreach (Warehouse::active()->get() as $warehouse) {
                            $data[self::STOCK_FIELD_PREFIX.$warehouse->id] = $record->stockAt($warehouse->id);
                        }

                        return $data;
                    })
                    ->using(function (Variant $record, array $data): Variant {
                        $record->update(self::withoutStockFields($data));
                        self::applyStock($record, $data);

                        return $record;
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function withoutStockFields(array $data): array
    {
        return collect($data)
            ->reject(fn ($value, string $key) => str_starts_with($key, self::STOCK_FIELD_PREFIX))
            ->all();
    }

    /**
     * Разложить введённые в форме числа по складам. Идёт через StockService, а не
     * прямым апдейтом, чтобы правка попала в журнал движений и пересчитала итог.
     *
     * @param  array<string, mixed>  $data
     */
    private static function applyStock(Variant $variant, array $data): void
    {
        $stock = app(StockService::class);

        foreach ($data as $key => $value) {
            if (! str_starts_with($key, self::STOCK_FIELD_PREFIX)) {
                continue;
            }
            $warehouseId = (int) substr($key, strlen(self::STOCK_FIELD_PREFIX));
            $stock->setQty($variant->id, $warehouseId, (int) $value, 'Правка в карточке товара');
        }
    }
}
