<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Telegram\TelegramNotifier;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TelegramSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = 'Уведомления в Telegram';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $title = 'Уведомления о заказах в Telegram';

    protected static string $view = 'filament.pages.telegram-settings';

    private const KEYS = [
        'telegram_notify_orders', 'telegram_bot_username', 'telegram_bot_token',
        'telegram_chat_ids', 'telegram_proxy',
    ];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'telegram_notify_orders' => (bool) SiteSetting::get('telegram_notify_orders', '1'),
            'telegram_bot_username' => SiteSetting::get('telegram_bot_username', ''),
            'telegram_bot_token' => SiteSetting::get('telegram_bot_token', ''),
            'telegram_chat_ids' => SiteSetting::get('telegram_chat_ids', ''),
            'telegram_proxy' => SiteSetting::get('telegram_proxy', ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Уведомления')
                    ->schema([
                        Forms\Components\Toggle::make('telegram_notify_orders')
                            ->label('Присылать уведомления о заказах')
                            ->helperText('Сообщение приходит при оформлении заказа и отдельно — когда подтверждена оплата.'),
                    ]),
                Forms\Components\Section::make('Бот и получатели')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_bot_username')
                            ->label('Никнейм бота')
                            ->prefix('@')
                            ->helperText('Только для наглядности — какой бот подключён. Заполняется сам при нажатии «Подключить бота». Сообщения бот отправляет по токену, а получателя определяет ID чата ниже.'),
                        Forms\Components\TextInput::make('telegram_bot_token')
                            ->label('Токен бота')
                            ->helperText('Выдаётся @BotFather, вид: 123456789:AAH...')
                            ->password()
                            ->revealable(),
                        Forms\Components\Textarea::make('telegram_chat_ids')
                            ->label('ID чатов получателей')
                            ->helperText('Куда присылать уведомления. Несколько — через запятую или с новой строки. Чтобы узнать ID: напишите боту команду /id — он ответит числом, его и вставьте сюда. Для группы добавьте бота в неё и напишите /id там (ID группы начинается с минуса).')
                            ->rows(3),
                    ]),
                Forms\Components\Section::make('Прокси')
                    ->description('Telegram недоступен с российских адресов, поэтому запросы к нему идут через зарубежный сервер. Если поле пустое, уведомления с этого сервера почти наверняка отправляться не будут.')
                    ->schema([
                        Forms\Components\TextInput::make('telegram_proxy')
                            ->label('Адрес прокси')
                            ->helperText('Вид: socks5://логин:пароль@адрес:порт или http://логин:пароль@адрес:порт. Без логина и пароля — просто socks5://адрес:порт.'),
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

    /** Подключение бота к сайту: адрес вебхука + команда /id в меню бота. */
    public function connectBot(): void
    {
        $this->save();

        $result = app(TelegramNotifier::class)->registerBot();

        if ($result['ok']) {
            $this->mount();

            Notification::make()
                ->title('Бот подключён')
                ->body('Напишите боту команду /id — он ответит ID чата, его вставьте в поле выше.')
                ->success()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Подключить бота не удалось')
            ->body($result['error'] ?? 'Неизвестная ошибка')
            ->danger()
            ->persistent()
            ->send();
    }

    /** Проверка связки «токен + прокси + чат» без ожидания реального заказа. */
    public function sendTest(): void
    {
        $this->save();

        $result = app(TelegramNotifier::class)->send(
            '🔔 Проверка связи с сайтом '.config('app.url').' — уведомления о заказах настроены.'
        );

        if ($result['ok']) {
            Notification::make()->title('Тестовое сообщение отправлено')->success()->send();

            return;
        }

        Notification::make()
            ->title('Отправить не удалось')
            ->body($result['error'] ?? 'Неизвестная ошибка')
            ->danger()
            ->persistent()
            ->send();
    }
}
