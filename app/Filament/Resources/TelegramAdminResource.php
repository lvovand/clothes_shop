<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TelegramAdminResource\Pages;
use App\Models\TelegramAdmin;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Кому открыт доступ в мини-приложение бота: список никнеймов Telegram.
 * Всё, что не в этом списке, приложение не пускает вообще.
 */
class TelegramAdminResource extends Resource
{
    protected static ?string $model = TelegramAdmin::class;

    protected static ?string $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Доступ в Telegram-приложение';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $modelLabel = 'доступ';

    protected static ?string $pluralModelLabel = 'доступы';

    protected static ?string $breadcrumb = 'Доступ в приложение';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('username')
                    ->label('Никнейм в Telegram')
                    ->prefix('@')
                    ->required()
                    ->maxLength(64)
                    ->helperText('Без «@», как в профиле человека (Telegram → Настройки → Имя пользователя). Регистр не важен.')
                    // Никнейм в базе нормализован мутатором модели, поэтому и проверять
                    // уникальность нужно по нормализованному значению.
                    ->rule(fn (?TelegramAdmin $record) => function ($attribute, $value, $fail) use ($record) {
                        $exists = TelegramAdmin::query()
                            ->where('username', TelegramAdmin::normalizeUsername($value))
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($exists) {
                            $fail('Такой никнейм уже есть в списке.');
                        }
                    }),
                Forms\Components\TextInput::make('name')
                    ->label('Кто это')
                    ->maxLength(120)
                    ->helperText('Подпись для себя: «Алексей, менеджер». На доступ не влияет.'),
                Forms\Components\Toggle::make('can_edit')
                    ->label('Может менять статусы заказов')
                    ->default(true)
                    ->helperText('Выключено — человек только смотрит заказы, изменить статус или оплату не может.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Доступ разрешён')
                    ->default(true)
                    ->helperText('Выключите, чтобы закрыть доступ, не удаляя запись.'),
                Forms\Components\Placeholder::make('telegram_id')
                    ->label('ID в Telegram')
                    ->content(fn (?TelegramAdmin $record) => $record?->telegram_id
                        ? (string) $record->telegram_id
                        : 'Заполнится сам при первом входе в приложение.')
                    ->helperText('Никнейм человек может сменить в любой момент, а этот номер — нет: после первого входа доступ держится на нём.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('username')
            ->columns([
                Tables\Columns\TextColumn::make('username')
                    ->label('Никнейм')
                    ->prefix('@')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Кто это')->searchable(),
                Tables\Columns\IconColumn::make('can_edit')->label('Меняет статусы')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->label('Доступ разрешён')->boolean(),
                Tables\Columns\TextColumn::make('last_seen_at')
                    ->label('Был в приложении')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('ещё не заходил'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Список пуст')
            ->emptyStateDescription('Пока никто не может открыть мини-приложение бота. Добавьте никнейм — и человек увидит заказы.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramAdmins::route('/'),
            'create' => Pages\CreateTelegramAdmin::route('/create'),
            'edit' => Pages\EditTelegramAdmin::route('/{record}/edit'),
        ];
    }
}
