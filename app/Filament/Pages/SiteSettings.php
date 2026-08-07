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
    ];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            collect(self::KEYS)->mapWithKeys(fn ($key) => [$key => SiteSetting::get($key)])->all()
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
            SiteSetting::set($key, $state[$key] ?? null);
        }

        Notification::make()->title('Настройки сохранены')->success()->send();
    }
}
