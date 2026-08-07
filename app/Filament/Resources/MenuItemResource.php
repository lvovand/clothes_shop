<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MenuItemResource\Pages;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Пункты всех меню сайта: верхнее меню, «SHOP» с категориями внутри и две колонки
 * футера. Пункт ведёт либо на категорию, либо на страницу, либо на произвольный
 * адрес — от выбора зависит, какое поле спрашивается дальше.
 */
class MenuItemResource extends Resource
{
    protected static ?string $model = MenuItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationLabel = 'Меню сайта — списком';

    // Основной способ править меню — страница «Меню сайта» с деревом. Этот список
    // остаётся как запасной (поиск, массовое удаление), но в меню админки не висит,
    // чтобы не было двух одинаковых по названию разделов.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationGroup = 'Контент';

    protected static ?string $modelLabel = 'пункт меню';

    protected static ?string $pluralModelLabel = 'пункты меню';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('menu_id')
                    ->label('Меню')
                    ->options(fn () => Menu::pluck('name', 'id'))
                    ->required()
                    ->live()
                    ->helperText('Главное меню — то, что открывается по кнопке в шапке. Остальные — колонки в футере.'),
                Forms\Components\Select::make('parent_id')
                    ->label('Вложен в пункт')
                    ->options(fn (Forms\Get $get, ?MenuItem $record) => MenuItem::query()
                        ->where('menu_id', $get('menu_id'))
                        ->whereNull('parent_id')
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->pluck('label', 'id'))
                    ->placeholder('— пункт верхнего уровня —')
                    ->helperText('Например, категории вложены в пункт SHOP.'),
                Forms\Components\TextInput::make('label')
                    ->label('Подпись')
                    ->required()
                    ->helperText('Текст, который видит покупатель.'),
                Forms\Components\Select::make('link_type')
                    ->label('Куда ведёт')
                    ->options([
                        'url' => 'Произвольный адрес',
                        Category::class => 'Категорию каталога',
                        Page::class => 'Страницу сайта',
                    ])
                    ->default('url')
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Forms\Components\Select $component, ?MenuItem $record) => $component->state($record?->linkable_type ?: 'url'))
                    ->helperText('Для категории и страницы адрес подставляется сам и остаётся верным при смене ссылки.'),
                Forms\Components\TextInput::make('url')
                    ->label('Адрес')
                    ->visible(fn (Forms\Get $get) => $get('link_type') === 'url')
                    ->helperText('Можно относительный, например /lookbook'),
                Forms\Components\Select::make('linkable_id')
                    ->label('Категория')
                    ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get) => $get('link_type') === Category::class)
                    ->required(fn (Forms\Get $get) => $get('link_type') === Category::class),
                Forms\Components\Select::make('linkable_id')
                    ->label('Страница')
                    ->options(fn () => Page::orderBy('title')->pluck('title', 'id'))
                    ->searchable()
                    ->visible(fn (Forms\Get $get) => $get('link_type') === Page::class)
                    ->required(fn (Forms\Get $get) => $get('link_type') === Page::class),
                Forms\Components\Hidden::make('linkable_type')
                    ->dehydrateStateUsing(fn (Forms\Get $get) => $get('link_type') === 'url' ? null : $get('link_type')),
                Forms\Components\Toggle::make('is_active')
                    ->label('Показывать на сайте')
                    ->default(true)
                    ->helperText('Пункт, ведущий на выключенную категорию или страницу, скрывается и сам, независимо от этого тумблера.'),
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
            ->defaultSort(fn ($query) => $query->orderBy('menu_id')->orderBy('sort_order')->orderBy('id'))
            ->columns([
                Tables\Columns\TextColumn::make('menu.name')->label('Меню')->sortable(),
                Tables\Columns\TextColumn::make('parent.label')->label('Вложен в')->placeholder('—'),
                Tables\Columns\TextColumn::make('label')->label('Подпись')->searchable(),
                Tables\Columns\TextColumn::make('target')
                    ->label('Ведёт на')
                    ->getStateUsing(fn (MenuItem $record) => $record->resolvedUrl()),
                Tables\Columns\IconColumn::make('is_active')->label('Показывать')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('menu_id')
                    ->label('Меню')
                    ->options(fn () => Menu::pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Изменить'),
                Tables\Actions\DeleteAction::make()->label('Удалить'),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make()->label('Удалить'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMenuItems::route('/'),
            'create' => Pages\CreateMenuItem::route('/create'),
            'edit' => Pages\EditMenuItem::route('/{record}/edit'),
        ];
    }
}
