<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\StockMovement;
use App\Models\Variant;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Журнал складских движений: что и когда изменило остаток. Записи создаются кодом
 * (заказ, отмена) и вручную — приход товара оформляется прямо здесь.
 */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Движения товара';

    protected static ?string $navigationGroup = 'Склад';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'движение';

    protected static ?string $pluralModelLabel = 'движения товара';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('variant_id')
                    ->label('Товар (вариант)')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => self::variantOptions(
                        Variant::with('product', 'attributeValues')
                            ->whereHas('product', fn ($q) => $q->where('name', 'like', '%'.self::escapeLike($search).'%'))
                            ->orWhere('sku', 'like', '%'.self::escapeLike($search).'%')
                            ->limit(30)->get()
                    ))
                    ->getOptionLabelUsing(fn ($value) => self::variantOptions(
                        Variant::with('product', 'attributeValues')->whereKey($value)->get()
                    )[$value] ?? $value)
                    ->helperText('Начните вводить название товара или артикул.'),
                Forms\Components\Select::make('warehouse_id')
                    ->label('Склад')
                    ->required()
                    ->options(Warehouse::active()->pluck('name', 'id')),
                Forms\Components\TextInput::make('delta')
                    ->label('Изменение, шт.')
                    ->numeric()
                    ->required()
                    ->helperText('Положительное число — приход (привезли товар), отрицательное — списание (брак, недостача).'),
                Forms\Components\TextInput::make('comment')
                    ->label('Комментарий')
                    ->maxLength(500)
                    ->helperText('Зачем сделано: «поставка от 8 августа», «пересчёт», «брак».'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Когда')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('variant.product.name')
                    ->label('Товар')
                    ->description(fn (StockMovement $record) => $record->variant?->attributeValues->pluck('label')->implode(', ')),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Склад'),
                Tables\Columns\TextColumn::make('delta')
                    ->label('Изменение')
                    ->formatStateUsing(fn (int $state) => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state) => $state > 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->formatStateUsing(fn (string $state) => StockMovement::REASONS[$state] ?? $state)
                    ->badge(),
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Заказ')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('comment')
                    ->label('Комментарий')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('Склад')
                    ->options(Warehouse::active()->pluck('name', 'id')),
                Tables\Filters\SelectFilter::make('reason')
                    ->label('Причина')
                    ->options(StockMovement::REASONS),
            ])
            ->actions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockMovements::route('/'),
            'create' => Pages\CreateStockMovement::route('/create'),
        ];
    }

    /** Записи журнала не редактируются и не удаляются: это история, а не справочник. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Провести ручное движение: остаток меняет StockService, он же пишет строку
     * журнала — поэтому форма создаёт запись через него, а не напрямую.
     */
    public static function applyManual(array $data): StockMovement
    {
        app(StockService::class)->adjust(
            (int) $data['variant_id'],
            (int) $data['warehouse_id'],
            (int) $data['delta'],
            $data['comment'] ?? null,
        );

        return StockMovement::latest('id')->first();
    }

    /** @param  Collection<int, Variant>  $variants */
    private static function variantOptions($variants): array
    {
        return $variants->mapWithKeys(fn (Variant $variant) => [
            $variant->id => trim(
                ($variant->product?->name ?? 'Товар #'.$variant->product_id)
                .' — '.($variant->attributeValues->pluck('label')->implode(', ') ?: 'без атрибутов')
                .($variant->sku ? ' ('.$variant->sku.')' : '')
            ),
        ])->all();
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $value);
    }
}
