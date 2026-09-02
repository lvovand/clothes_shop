<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Категории';

    protected static ?string $navigationGroup = 'Каталог';

    protected static ?string $modelLabel = 'категория';

    protected static ?string $pluralModelLabel = 'категории';

    public static function form(Form $form): Form
    {
        return $form
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
                Forms\Components\FileUpload::make('image')
                    ->label('Изображение (плитка на главной)')
                    ->image()
                    ->imageEditor()
                    ->directory('categories'),
                Forms\Components\FileUpload::make('thumb_path')
                    ->label('Превью (необязательно)')
                    ->helperText('Оставьте пустым — превью сделается из изображения выше автоматически. Загрузите свою картинку, если нужен другой кадр; кроп и поворот правятся кнопкой карандаша на загруженном файле.')
                    ->image()
                    ->imageEditor()
                    ->directory('categories'),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_active')
                    ->label('Активна'),
                Forms\Components\Toggle::make('is_virtual')
                    ->label('Показывать все товары каталога')
                    ->helperText('Так работает раздел ALL: товары к нему не привязываются, он всегда показывает весь каталог целиком. Для обычной категории оставьте выключенным.'),
                Forms\Components\Section::make('Тип категории')
                    ->description('Закрытая категория не показывается на сайте: её нет в меню, плитках, поиске и карте сайта. Покупатель попадает в неё только по прямой ссылке и после ввода промокода. Товары такой категории можно смотреть, но нельзя положить в корзину.')
                    ->schema([
                        Forms\Components\Toggle::make('is_private')
                            ->label('Закрытая')
                            ->live()
                            ->helperText('Выключено — обычная категория, видна всем.'),
                        Forms\Components\TextInput::make('access_code')
                            ->label('Промокод для доступа')
                            ->maxLength(64)
                            ->required(fn (Forms\Get $get) => (bool) $get('is_private'))
                            ->visible(fn (Forms\Get $get) => (bool) $get('is_private'))
                            ->helperText('Его вводят на странице категории. Регистр не важен.'),
                        Forms\Components\Placeholder::make('private_url')
                            ->label('Ссылка на категорию')
                            ->visible(fn (Forms\Get $get) => (bool) $get('is_private'))
                            ->content(fn (Forms\Get $get) => $get('slug')
                                ? url('/catalog/'.$get('slug'))
                                : 'Ссылка появится после сохранения категории.')
                            ->helperText('Отправьте её покупателю вместе с промокодом — сам он эту категорию на сайте не найдёт.'),
                    ]),
                Forms\Components\Section::make('SEO')
                    ->columns(2)
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')
                            ->label('Meta title')
                            ->maxLength(160)
                            ->helperText('Заголовок вкладки и строка в поиске. Пусто — берётся название категории. Бренд добавляется автоматически.'),
                        Forms\Components\TextInput::make('meta_description')
                            ->label('Meta description')
                            ->maxLength(320)
                            ->helperText('Описание под ссылкой в выдаче. Пусто — берётся общее описание из «Настройки сайта».'),
                        Forms\Components\Textarea::make('seo_text')
                            ->label('Текст под каталогом')
                            ->rows(5)
                            ->columnSpanFull()
                            ->helperText('Необязательно. Показывается внизу страницы категории под товарами — место для описания раздела под поисковые запросы.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\ImageColumn::make('image')->label('Фото'),
                Tables\Columns\TextColumn::make('name')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('URL')->searchable(),
                // У виртуальной категории (ALL) привязанных товаров нет — она показывает
                // весь каталог, поэтому обычный счётчик связей давал 0 и читался как
                // «раздел пустой».
                Tables\Columns\TextColumn::make('products_count')
                    ->label('Товаров')
                    ->counts('products')
                    ->state(fn (Category $record) => $record->is_virtual
                        ? Product::published()->count().' (весь каталог)'
                        : $record->products_count),
                Tables\Columns\IconColumn::make('is_active')->label('Активна')->boolean(),
                Tables\Columns\IconColumn::make('is_private')->label('Закрытая')->boolean(),
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
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }
}
