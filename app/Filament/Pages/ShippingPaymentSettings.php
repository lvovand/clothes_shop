<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class ShippingPaymentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Доставка и оплата';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $title = 'Настройки доставки и оплаты';

    protected static string $view = 'filament.pages.shipping-payment-settings';

    /**
     * Точки отправления (город и пункт сдачи у СДЭК, точка сдачи у Яндекса) здесь
     * больше не задаются: они принадлежат складу — «Склад → Склады». Заказ едет
     * из того города, где лежит товар, поэтому одной общей настройки мало.
     */
    private const KEYS = [
        'cdek_client_id', 'cdek_client_secret', 'yandex_map_api_key',
        'yandex_delivery_token',
        'yandex_delivery_sender_phone', 'yandex_delivery_sender_name',
        'parcel_weight', 'parcel_dx', 'parcel_dy', 'parcel_dz',
        'yandex_auto_create', 'cdek_auto_create',
        'tbank_terminal_key', 'tbank_secret_key',
        'yandex_pay_merchant_id', 'yandex_pay_api_key', 'yandex_pay_env',
        'dadata_api_key',
    ];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            collect(self::KEYS)->mapWithKeys(function ($key) {
                $configKey = match ($key) {
                    'cdek_client_id' => 'services.cdek.client_id',
                    'cdek_client_secret' => 'services.cdek.client_secret',
                    'cdek_sender_city_code' => 'services.cdek.sender_city_code',
                    'yandex_map_api_key' => 'services.cdek.yandex_map_api_key',
                    'tbank_terminal_key' => 'services.tbank.terminal_key',
                    'tbank_secret_key' => 'services.tbank.secret_key',
                    'yandex_pay_merchant_id' => 'services.yandex_pay.merchant_id',
                    'yandex_pay_api_key' => 'services.yandex_pay.api_key',
                    'yandex_pay_env' => 'services.yandex_pay.env',
                    'yandex_delivery_token' => 'services.yandex_delivery.token',
                    'yandex_delivery_dropoff_id' => 'services.yandex_delivery.dropoff_id',
                    default => null,
                };

                $value = SiteSetting::get($key, $configKey ? config($configKey) : null);

                // Автосоздание включено по умолчанию: пустая настройка не должна
                // выглядеть как «выключено».
                if (in_array($key, ['yandex_auto_create', 'cdek_auto_create'], true)) {
                    $value = $value === null ? true : (bool) $value;
                }

                return [$key => $value];
            })->all()
        );
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('CDEK')
                    ->description('Данные для доступа к API СДЭК — раздел "Интеграция" в личном кабинете cdek.ru')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('cdek_client_id')->label('Client ID'),
                        Forms\Components\TextInput::make('cdek_client_secret')->label('Client Secret')->password()->revealable(),
                        // Город отправления и пункт сдачи переехали к складу: заказ
                        // едет оттуда, где лежит товар, и у каждого города отгрузки
                        // свои точки.
                        Forms\Components\Placeholder::make('cdek_origin_hint')
                            ->label('Откуда отправляются заказы')
                            ->columnSpanFull()
                            ->content('Город отправления и пункт сдачи посылок задаются у каждого склада — «Склад → Склады». Доставка считается от того города, где лежит товар: заказ из Москвы не считается по оренбургскому тарифу.'),
                        Forms\Components\Toggle::make('cdek_auto_create')
                            ->label('Создавать заявку автоматически')
                            ->default(true)
                            ->columnSpanFull()
                            ->helperText('Включено: как только заказ оплачен, заявка в СДЭК создаётся сама, а в Telegram приходит её номер. Выключено: заявки оформляются вручную — в кабинете СДЭК или кнопкой «Создать заявку» в самом заказе.'),
                        Forms\Components\TextInput::make('yandex_map_api_key')
                            ->label('Ключ Яндекс.Карт (для виджета выбора ПВЗ)')
                            ->helperText('Нужен официальному виджету СДЭК для отрисовки карты на чекауте — ключ типа "JavaScript API и HTTP Геокодер" с сайта yandex.ru/maps-api, привязанный к домену сайта.'),
                    ]),
                Forms\Components\Section::make('Подсказки адреса (DaData)')
                    ->description('Автодополнение города, улицы и дома при оформлении заказа. Ключ — на dadata.ru, раздел «API-ключи» (бесплатно 10 000 подсказок в сутки). Секретный ключ здесь не нужен: он требуется только «Стандартизации».')
                    ->schema([
                        Forms\Components\TextInput::make('dadata_api_key')
                            ->label('API-ключ DaData')
                            ->password()
                            ->revealable()
                            ->helperText('Если поле пустое, подсказки берутся из бесплатного OpenStreetMap — они заметно беднее: находят улицу только по началу названия.'),
                    ]),
                Forms\Components\Section::make('Яндекс Доставка')
                    ->description('Токен из личного кабинета Яндекс Доставки (раздел «Интеграция → API»). Посылки едут не от нашего адреса, а из точки сдачи Яндекса: заказ отвозится туда, дальше Яндекс доставляет покупателю в ПВЗ или курьером.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('yandex_delivery_token')
                            ->label('OAuth-токен')
                            ->password()
                            ->revealable()
                            ->live(onBlur: true)
                            ->helperText('Строка вида y0_… Без него способы доставки Яндекса не считаются.'),
                        Forms\Components\Placeholder::make('yandex_dropoff_hint')
                            ->label('Откуда сдаём посылки')
                            ->content('Город и точка сдачи задаются у каждого склада — «Склад → Склады». Там же карта с точками Яндекса.'),
                        Forms\Components\TextInput::make('yandex_delivery_sender_phone')
                            ->label('Телефон отправителя')
                            ->helperText('В формате +7… Если пусто — берётся телефон из контактов сайта.'),
                        Forms\Components\TextInput::make('yandex_delivery_sender_name')
                            ->label('Имя отправителя')
                            ->helperText('Если пусто — название магазина.'),
                        Forms\Components\Toggle::make('yandex_auto_create')
                            ->label('Создавать заявку автоматически')
                            ->default(true)
                            ->columnSpanFull()
                            ->helperText('Включено: как только заказ оплачен, заявка в Яндекс Доставке создаётся сама, а в Telegram приходит её номер. Выключено: заявки оформляются вручную — в кабинете Яндекса или кнопкой «Создать заявку» в самом заказе.'),
                        Forms\Components\Placeholder::make('yandex_delivery_methods_hint')
                            ->label('Показ способов покупателю')
                            ->columnSpanFull()
                            ->content('Включаются и выключаются в разделе «Заказы → Способы доставки»: «Яндекс Доставка — пункт выдачи» и «Яндекс Доставка — курьер». Там же можно выключить способы СДЭК.'),
                    ]),
                Forms\Components\Section::make('Посылка по умолчанию')
                    ->description('Общие размеры для всех перевозчиков: по ним считается доставка и оформляются заявки.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('parcel_weight')
                            ->label('Вес одной единицы товара, г')
                            ->numeric()
                            ->default(500)
                            ->helperText('Берётся, когда у товара не заполнен свой вес.'),
                        Forms\Components\TextInput::make('parcel_dx')->label('Длина посылки, см')->numeric()->default(30),
                        Forms\Components\TextInput::make('parcel_dy')->label('Ширина посылки, см')->numeric()->default(25),
                        Forms\Components\TextInput::make('parcel_dz')->label('Высота посылки, см')->numeric()->default(10),
                    ]),
                Forms\Components\Section::make('Т-Банк (приём платежей)')
                    ->description('Данные терминала — личный кабинет Т-Банк Эквайринг')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('tbank_terminal_key')->label('Terminal Key'),
                        Forms\Components\TextInput::make('tbank_secret_key')->label('Secret Key (пароль терминала)')->password()->revealable(),
                    ]),
                Forms\Components\Section::make('Яндекс Пэй и Сплит')
                    ->description('Данные из личного кабинета Яндекс Пэй. Сплит (оплата частями) — не отдельная интеграция, а дополнительный способ внутри Яндекс Пэй, поэтому ключи для них общие.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('yandex_pay_merchant_id')
                            ->label('Merchant ID')
                            ->helperText('Идентификатор магазина, вида 8-4-4-4-12 символов.'),
                        Forms\Components\TextInput::make('yandex_pay_api_key')
                            ->label('Merchant API ключ')
                            ->password()
                            ->revealable(),
                        Forms\Components\Select::make('yandex_pay_env')
                            ->label('Окружение')
                            ->options([
                                'production' => 'Боевое (реальные платежи)',
                                'sandbox' => 'Песочница (тестовые платежи)',
                            ])
                            ->default('production')
                            ->selectablePlaceholder(false)
                            ->helperText('В песочнице ключом служит сам Merchant ID — впишите его в оба поля.'),
                        Forms\Components\Placeholder::make('yandex_pay_webhook_url')
                            ->label('Callback URL для личного кабинета')
                            ->content(url('/webhooks/yandex-pay'))
                            ->helperText('Укажите этот адрес в настройках Яндекс Пэй. Часть /v1/webhook Яндекс добавит к нему сам — дописывать её не нужно.'),
                        Forms\Components\Placeholder::make('yandex_pay_visibility')
                            ->label('Показывать ли способ покупателю')
                            ->content('Настраивается в разделе «Настройки → Способы оплаты» — там же, где Т-Банк.'),
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

        // Force a fresh CDEK auth on next request in case the client credentials just changed.
        Cache::forget('cdek_access_token');

        Notification::make()->title('Настройки сохранены')->success()->send();
    }
}
