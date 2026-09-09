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
                    ->live()
                    ->unique(ignoreRecord: true),
                Forms\Components\Placeholder::make('category_url')
                    ->label('Ссылка на категорию')
                    ->content(function (Forms\Get $get) {
                        if (! $get('slug')) {
                            return 'Ссылка появится после заполнения URL (slug).';
                        }

                        $url = url('/catalog/'.$get('slug'));
                        $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data='.urlencode($url);
                        $urlHtml = e($url);
                        $urlJs = addslashes($url);

                        return new \Illuminate\Support\HtmlString(<<<HTML
                            <div x-data="{ copied: false }" class="flex items-start gap-4">
                                <div class="flex flex-1 flex-wrap items-center gap-2">
                                    <code class="text-sm break-all">{$urlHtml}</code>
                                    <button
                                        type="button"
                                        x-on:click="navigator.clipboard.writeText('{$urlJs}'); copied = true; setTimeout(() => copied = false, 1500)"
                                        class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800"
                                    >
                                        <span x-show="!copied">Копировать</span>
                                        <span x-show="copied" x-cloak>Скопировано</span>
                                    </button>
                                </div>
                                <img src="{$qrSrc}" alt="QR-код на категорию" width="120" height="120" class="rounded border border-gray-200 dark:border-gray-700" />
                            </div>
                        HTML);
                    })
                    ->helperText('QR-код и кнопка «Копировать» ведут на эту же ссылку — удобно отправить покупателю.'),
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
                            ->helperText('Его вводят на странице категории. Регистр не важен. Отправьте покупателю вместе со ссылкой выше — сам он эту категорию на сайте не найдёт.'),
                    ]),
                Forms\Components\Section::make('Запуск коллекции')
                    ->description('Пока идёт отсчёт, по адресу ниже показывается страница ожидания с таймером. В назначенное время раздел открывается сам: товары становятся видны и доступны к покупке всем, промокод больше не нужен, страница ожидания начинает вести в раздел.')
                    ->visible(fn (Forms\Get $get) => (bool) $get('is_private'))
                    ->schema([
                        Forms\Components\DateTimePicker::make('launch_at')
                            ->label('Открыть раздел')
                            ->seconds(false)
                            ->native(false)
                            ->displayFormat('d.m.Y H:i')
                            // Время вводится и показывается по Москве, в базе лежит в UTC.
                            ->timezone('Europe/Moscow')
                            ->helperText('Время московское. Пусто — раздел остаётся закрытым, открыть его можно только промокодом.'),
                        Forms\Components\TextInput::make('teaser_slug')
                            ->label('Адрес страницы ожидания')
                            ->prefix(url('/').'/')
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->unique(ignoreRecord: true)
                            ->rules(['regex:/^[a-z0-9\-]+$/'])
                            ->validationMessages(['regex' => 'Только латинские буквы, цифры и дефис.'])
                            ->helperText('Отдельный от раздела адрес — его можно давать в рассылке и сторис. Например: all-star-collection'),
                        Forms\Components\Placeholder::make('teaser_url')
                            ->label('Ссылка на страницу ожидания')
                            ->visible(fn (Forms\Get $get) => (bool) $get('teaser_slug'))
                            ->content(function (Forms\Get $get) {
                                $url = url('/'.$get('teaser_slug'));
                                $urlHtml = e($url);
                                $urlJs = addslashes($url);

                                return new \Illuminate\Support\HtmlString(<<<HTML
                                    <div x-data="{ copied: false }" class="flex flex-wrap items-center gap-2">
                                        <code class="text-sm break-all">{$urlHtml}</code>
                                        <button
                                            type="button"
                                            x-on:click="navigator.clipboard.writeText('{$urlJs}'); copied = true; setTimeout(() => copied = false, 1500)"
                                            class="inline-flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1 text-xs font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800"
                                        >
                                            <span x-show="!copied">Копировать</span>
                                            <span x-show="copied" x-cloak>Скопировано</span>
                                        </button>
                                    </div>
                                HTML);
                            }),
                        Forms\Components\TextInput::make('teaser_title')
                            ->label('Заголовок на странице ожидания')
                            ->maxLength(120)
                            ->helperText('Пусто — берётся название категории.'),
                        Forms\Components\Textarea::make('teaser_lead')
                            ->label('Текст над таймером')
                            ->rows(3)
                            ->helperText('Короткая строка о дате старта, например: коллекция будет доступна 16 сентября в 12:00.'),
                        Forms\Components\FileUpload::make('teaser_image')
                            ->label('Баннер над заголовком')
                            ->image()
                            ->imageEditor()
                            ->directory('collections'),
                        Forms\Components\FileUpload::make('teaser_image_mobile')
                            ->label('Баннер для телефона (необязательно)')
                            ->image()
                            ->imageEditor()
                            ->directory('collections')
                            ->helperText('Пусто — на телефоне показывается тот же баннер.'),
                        \App\Forms\Components\HtmlEditor::make('teaser_body')
                            ->label('Текст под таймером')
                            ->minHeight(400)
                            ->helperText('Описание коллекции: абзацы, списки, ссылки и картинки — как на обычных страницах сайта.'),
                        Forms\Components\Toggle::make('show_in_menu')
                            ->label('Показывать пункт в левом меню')
                            ->live()
                            ->helperText('Пункт появится в главном меню сайта. До старта он ведёт на страницу ожидания, после — в сам раздел. Порядок пунктов правится в разделе «Меню в шапке».'),
                        Forms\Components\TextInput::make('menu_label')
                            ->label('Название пункта меню')
                            ->maxLength(120)
                            ->visible(fn (Forms\Get $get) => (bool) $get('show_in_menu'))
                            ->helperText('Пусто — берётся название категории.'),
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
