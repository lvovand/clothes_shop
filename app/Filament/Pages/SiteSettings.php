<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SiteSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Настройки сайта';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $title = 'Настройки сайта';

    protected static string $view = 'filament.pages.site-settings';

    private const KEYS = [
        'brand_name', 'favicon', 'footer_phone', 'footer_email',
        'footer_address', 'footer_map_url', 'footer_hours', 'social_instagram', 'social_telegram',
        'home_new_title', 'home_new_cta', 'home_shop_title', 'home_shop_cta', 'home_shop_tiles_count',
        'home_marquee_text', 'home_marquee_image',
        'catalog_default_sort',
        'seo_home_title', 'seo_home_description', 'seo_catalog_description', 'seo_default_description', 'og_image',
        'feed_shop_name', 'feed_company', 'feed_yandex_enabled', 'feed_google_enabled',
    ];

    /** Тумблеры, которые по умолчанию включены, пока их явно не выключили. */
    private const BOOL_ON_BY_DEFAULT = ['feed_yandex_enabled', 'feed_google_enabled'];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            collect(self::KEYS)->mapWithKeys(fn ($key) => [
                $key => SiteSetting::get($key, in_array($key, self::BOOL_ON_BY_DEFAULT, true) ? '1' : null),
            ])->all()
        );
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Бренд')
                    ->schema([
                        Forms\Components\TextInput::make('brand_name')->label('Название бренда (шапка/футер)'),
                        Forms\Components\FileUpload::make('favicon')
                            ->label('Favicon (иконка вкладки браузера)')
                            ->image()
                            ->imageEditor()
                            ->directory('site')
                            ->helperText('Квадратное изображение, будет уменьшено браузером само'),
                    ]),
                Forms\Components\Section::make('Каталог')
                    ->schema([
                        Forms\Components\Select::make('catalog_default_sort')
                            ->label('Сортировка товаров по умолчанию')
                            ->options([
                                'manual' => 'Вручную (поле «Сортировка» у товара)',
                                'date' => 'Сначала новые',
                                'price_asc' => 'Цена: по возрастанию',
                                'price_desc' => 'Цена: по убыванию',
                            ])
                            ->default('manual')
                            ->selectablePlaceholder(false)
                            ->helperText('Применяется, пока покупатель сам не выбрал сортировку в каталоге.'),
                    ]),
                Forms\Components\Section::make('SEO / Поиск')
                    ->description('Заголовки и описания для страниц, у которых нет своего поля: главная, каталог, поиск. У товаров и статических страниц эти поля — в самой карточке товара/страницы, у категорий — в разделе «Каталог → Категории».')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('seo_home_title')
                            ->label('Заголовок главной')
                            ->maxLength(160)
                            ->helperText('<title> главной страницы. Пусто — только название бренда.'),
                        Forms\Components\Textarea::make('seo_home_description')
                            ->label('Описание главной')
                            ->rows(2)
                            ->maxLength(320)
                            ->helperText('Текст под ссылкой на главную в выдаче.'),
                        Forms\Components\Textarea::make('seo_catalog_description')
                            ->label('Описание каталога')
                            ->rows(2)
                            ->maxLength(320)
                            ->helperText('Для страницы «весь каталог» и категорий без своего описания.'),
                        Forms\Components\Textarea::make('seo_default_description')
                            ->label('Описание по умолчанию')
                            ->rows(2)
                            ->maxLength(320)
                            ->helperText('Подставляется на любой странице, где нет более точного описания. Также идёт в превью для соцсетей.'),
                        Forms\Components\FileUpload::make('og_image')
                            ->label('Картинка-превью для соцсетей (OG image)')
                            ->image()
                            ->imageEditor()
                            ->directory('site')
                            ->columnSpanFull()
                            ->helperText('Показывается при отправке ссылки на сайт в мессенджер или соцсеть. Рекомендуемый размер 1200×630. Пусто — логотип бренда.'),
                    ]),
                Forms\Components\Section::make('Товарные фиды (Яндекс Маркет, Google)')
                    ->description('Выгрузка каталога для товарных площадок. Адреса фидов: /feeds/yandex-market.yml и /feeds/google-merchant.xml — их указывают в кабинете площадки.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('feed_shop_name')
                            ->label('Название магазина в фиде')
                            ->helperText('Короткое имя магазина. Пусто — название бренда.'),
                        Forms\Components\TextInput::make('feed_company')
                            ->label('Юридическое название (компания)')
                            ->helperText('Название организации-владельца. Пусто — название бренда.'),
                        Forms\Components\Toggle::make('feed_yandex_enabled')
                            ->label('Отдавать фид Яндекс Маркета (YML)')
                            ->default(true),
                        Forms\Components\Toggle::make('feed_google_enabled')
                            ->label('Отдавать фид Google Merchant (XML)')
                            ->default(true),
                    ]),
                Forms\Components\Section::make('Контакты (футер)')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('footer_phone')->label('Телефон'),
                        Forms\Components\TextInput::make('footer_email')->label('Email'),
                        Forms\Components\TextInput::make('footer_address')->label('Адрес'),
                        Forms\Components\TextInput::make('footer_map_url')
                            ->label('Ссылка на карту (куда ведёт адрес)')
                            ->url()
                            ->helperText('Карточка магазина на Яндекс.Картах. По этой ссылке открывается адрес в футере и в меню.'),
                        Forms\Components\TextInput::make('footer_hours')->label('Часы работы'),
                    ]),
                Forms\Components\Section::make('Соцсети')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('social_instagram')->label('Instagram (ссылка)')->url(),
                        Forms\Components\TextInput::make('social_telegram')->label('Telegram (ссылка)')->url(),
                    ]),
                Forms\Components\Section::make('Главная страница — подписи разделов')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('home_new_title')->label('Заголовок блока новинок'),
                        Forms\Components\TextInput::make('home_new_cta')->label('Ссылка "смотреть всё" (новинки)'),
                        Forms\Components\TextInput::make('home_shop_title')->label('Заголовок блока категорий'),
                        Forms\Components\TextInput::make('home_shop_cta')->label('Ссылка "смотреть всё" (категории)'),
                        Forms\Components\TextInput::make('home_shop_tiles_count')
                            ->label('Сколько категорий показывать в блоке')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(12)
                            ->default(4)
                            ->helperText('Берутся первые категории в том порядке, в котором они стоят в «Каталог → Категории». Картинка ячейки — поле «Изображение» у самой категории.'),
                        Forms\Components\TextInput::make('home_marquee_text')
                            ->label('Бегущая строка')
                            ->helperText('Можно выделить слово покрупнее тегом <strong>, например: <strong>VACATION</strong> COLLECTION SS26')
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('home_marquee_image')
                            ->label('Иконка в бегущей строке')
                            ->image()
                            ->imageEditor()
                            ->directory('site')
                            ->helperText('Необязательно — маленькая иконка, повторяется между текстом в бегущей строке')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::KEYS as $key) {
            $value = $state[$key] ?? null;
            SiteSetting::set($key, is_bool($value) ? ($value ? '1' : '0') : $value);
        }

        Notification::make()->title('Настройки сохранены')->success()->send();
    }
}
