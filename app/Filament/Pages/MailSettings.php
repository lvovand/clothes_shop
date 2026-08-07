<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class MailSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Почта (SMTP)';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $title = 'Настройки почты';

    protected static string $view = 'filament.pages.mail-settings';

    private const KEYS = [
        'mail_mailer', 'mail_host', 'mail_port', 'mail_encryption',
        'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name',
    ];

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(
            collect(self::KEYS)->mapWithKeys(function ($key) {
                $configKey = match ($key) {
                    'mail_mailer' => 'mail.default',
                    'mail_host' => 'mail.mailers.smtp.host',
                    'mail_port' => 'mail.mailers.smtp.port',
                    'mail_username' => 'mail.mailers.smtp.username',
                    'mail_password' => 'mail.mailers.smtp.password',
                    'mail_from_address' => 'mail.from.address',
                    'mail_from_name' => 'mail.from.name',
                    default => null,
                };

                $default = $configKey === null ? null : config($configKey);

                return [$key => SiteSetting::get($key, $default === null ? null : (string) $default)];
            })->all()
        );
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Отправка писем')
                    ->description('Пока стоит "Только лог", письма (например, подарочные сертификаты) не доставляются реальным получателям — они только пишутся в технический лог сервера. Чтобы письма реально уходили, укажите здесь данные реального SMTP-сервера (например, от Яндекс 360, Mail.ru или другого провайдера почты).')
                    ->schema([
                        Forms\Components\Select::make('mail_mailer')
                            ->label('Способ отправки')
                            ->options([
                                'smtp' => 'SMTP (реальная отправка)',
                                'log' => 'Только лог (ничего не отправлять по-настоящему)',
                            ])
                            ->required(),
                    ]),
                Forms\Components\Section::make('SMTP-сервер')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('mail_host')->label('Хост (SMTP host)'),
                        Forms\Components\TextInput::make('mail_port')->label('Порт')->numeric(),
                        Forms\Components\Select::make('mail_encryption')
                            ->label('Шифрование')
                            ->options([
                                '' => 'Авто (STARTTLS, обычно порт 587)',
                                'ssl' => 'SSL (обычно порт 465)',
                            ]),
                        Forms\Components\TextInput::make('mail_username')->label('Логин'),
                        Forms\Components\TextInput::make('mail_password')->label('Пароль')->password()->revealable(),
                    ]),
                Forms\Components\Section::make('Отправитель')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('mail_from_address')->label('Email отправителя')->email(),
                        Forms\Components\TextInput::make('mail_from_name')->label('Имя отправителя'),
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
