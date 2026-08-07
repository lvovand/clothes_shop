<?php

namespace App\Filament\Resources;

use App\Models\HomepageSlide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

abstract class HomepageSlideResourceBase extends Resource
{
    protected static ?string $model = HomepageSlide::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Контент';

    protected static ?string $modelLabel = 'слайд';

    protected static ?string $pluralModelLabel = 'слайды';

    abstract public static function device(): string;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('device', static::device());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Hidden::make('device')->default(static::device()),
                Forms\Components\Section::make()
                    ->description('Каждый активный слайд показывается по очереди на главной странице сайта (карусель, автосмена каждые 5 секунд).')
                    ->schema([
                        Forms\Components\FileUpload::make('image')->label('Фото')->image()->imageEditor()->directory('slides')->required(),
                        Forms\Components\TextInput::make('link_url')->label('Ссылка при клике на слайд'),
                        Forms\Components\TextInput::make('link_text')->label('Текст кнопки/подписи на слайде'),
                        Forms\Components\Toggle::make('is_active')->label('Показывать на сайте')->default(true),
                        Forms\Components\TextInput::make('sort_order')->label('Порядок показа')->numeric()->default(0),
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
                Tables\Columns\TextColumn::make('link_text')->label('Подпись'),
                Tables\Columns\IconColumn::make('is_active')->label('Активен')->boolean(),
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
}
