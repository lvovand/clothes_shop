<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Страницы';

    protected static ?string $navigationGroup = 'Контент';

    protected static ?string $modelLabel = 'страница';

    protected static ?string $pluralModelLabel = 'страницы';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Основное')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')->label('Заголовок')->required(),
                        Forms\Components\TextInput::make('subtitle')->label('Подзаголовок (для некоторых шаблонов, напр. "About")'),
                        Forms\Components\TextInput::make('breadcrumb_title')
                            ->label('Название в хлебных крошках')
                            ->helperText('Если пусто — берётся заголовок страницы. Например: DELIVERY AND RETURN'),
                        Forms\Components\TextInput::make('slug')->label('URL (slug)')->required()->unique(ignoreRecord: true),
                        Forms\Components\Toggle::make('is_active')->label('Опубликована'),
                        Forms\Components\TextInput::make('template')->label('Шаблон (техническое, не трогать без надобности)'),
                    ]),
                Forms\Components\FileUpload::make('image')
                    ->label('Изображение страницы (десктоп)')
                    ->image()
                    ->imageEditor()
                    ->directory('pages')
                    ->helperText('На обычных страницах показывается в шапке, на "About" — фото сбоку от текста, на "Loyalty Card" — фото-баннер (десктоп/широкий экран)'),
                Forms\Components\FileUpload::make('image_mobile')
                    ->label('Изображение страницы (моб.)')
                    ->image()
                    ->imageEditor()
                    ->directory('pages')
                    ->helperText('Используется только шаблоном "Loyalty Card" — версия фото-баннера для мобильных экранов'),
                // Визуальный редактор (наше поле на TinyMCE, а не штатный RichEditor:
                // тот на Trix и вычищает незнакомые теги/классы, чем ломает вёрстку
                // страниц). Исходный HTML доступен кнопкой «Исходный код» на панели.
                \App\Forms\Components\HtmlEditor::make('body')
                    ->label('Текст страницы')
                    ->helperText('Правьте текст как в обычном редакторе. Разметка страницы сохраняется как есть; посмотреть или поправить её вручную можно кнопкой «Исходный код» на панели.')
                    ->minHeight(560)
                    ->columnSpanFull(),
                Forms\Components\Section::make('SEO')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        Forms\Components\TextInput::make('meta_title')->label('Meta title'),
                        Forms\Components\TextInput::make('meta_description')->label('Meta description'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Заголовок')->searchable(),
                Tables\Columns\TextColumn::make('slug')->label('URL')->searchable(),
                Tables\Columns\IconColumn::make('is_active')->label('Опубликована')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('Обновлена')->dateTime()->sortable(),
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
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
