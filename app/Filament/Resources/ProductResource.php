<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Товары';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'товар';

    protected static ?string $pluralModelLabel = 'товары';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основное')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (string $state, Forms\Set $set) => $set('slug', Str::slug($state))),
                        Forms\Components\TextInput::make('slug')
                            ->label('URL (slug)')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('categories')
                            ->label('Категории')
                            ->relationship('categories', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('Товар может быть сразу в нескольких категориях'),
                        Forms\Components\Select::make('category_id')
                            ->label('Основная категория')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Используется для хлебных крошек и канонической ссылки'),
                        Forms\Components\Select::make('status')
                            ->label('Статус')
                            ->options(['draft' => 'Черновик', 'published' => 'Опубликован'])
                            ->default('published')
                            ->required(),
                        Forms\Components\Toggle::make('is_new')
                            ->label('Новинка'),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Порядок сортировки')
                            ->numeric()
                            ->default(0),
                    ]),
                Forms\Components\Section::make('SEO')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')->label('Meta title'),
                        Forms\Components\TextInput::make('meta_description')->label('Meta description'),
                    ]),
                Forms\Components\Section::make('Габариты (для расчёта доставки)')
                    ->columns(4)
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('weight_kg')->label('Вес, кг')->numeric(),
                        Forms\Components\TextInput::make('length_cm')->label('Длина, см')->numeric(),
                        Forms\Components\TextInput::make('width_cm')->label('Ширина, см')->numeric(),
                        Forms\Components\TextInput::make('height_cm')->label('Высота, см')->numeric(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('images.0.path')->label('Фото'),
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('category.name')->label('Категория')->sortable(),
                Tables\Columns\IconColumn::make('is_new')->label('Новинка')->boolean(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge(),
                Tables\Columns\TextColumn::make('sort_order')->label('Сортировка')->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлён')->dateTime()->sortable(),
            ])
            // Вторичная сортировка по id обязательна: у всех товаров sort_order = 0,
            // а при одинаковых значениях порядок строк между страницами не определён —
            // из-за этого один товар мог показаться на двух страницах, а другой пропасть.
            ->defaultSort(fn ($query) => $query->orderBy('sort_order')->orderBy('id'))
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')->label('Категория')->relationship('category', 'name'),
                Tables\Filters\TernaryFilter::make('is_new')->label('Новинка'),
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
        return [
            RelationManagers\ImagesRelationManager::class,
            RelationManagers\VariantsRelationManager::class,
            RelationManagers\ContentBlocksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
