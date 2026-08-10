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
