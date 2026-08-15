<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CodeSnippetResource\Pages;
use App\Models\CodeSnippet;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Код счётчиков и метрик, который вставляется в страницы сайта.
 *
 * Блоков может быть сколько угодно, у каждого своё место вставки: сервисы
 * аналитики требуют разного (Метрика — в <head>, часть чатов — перед </body>).
 */
class CodeSnippetResource extends Resource
{
    protected static ?string $model = CodeSnippet::class;

    protected static ?string $navigationIcon = 'heroicon-o-code-bracket';

    protected static ?string $navigationLabel = 'Код на сайте';

    protected static ?string $navigationGroup = 'Настройки';

    protected static ?string $modelLabel = 'блок кода';

    protected static ?string $pluralModelLabel = 'блоки кода';

    protected static ?string $breadcrumb = 'Код на сайте';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('Название блока')
                    ->required()
                    ->maxLength(150)
                    ->helperText('Для себя, чтобы отличать блоки друг от друга: «Яндекс.Метрика», «Пиксель ВК». На сайте не показывается.'),
                Forms\Components\Select::make('position')
                    ->label('Куда вставлять')
                    ->options(CodeSnippet::POSITIONS)
                    ->default('head')
                    ->required()
                    ->native(false)
                    ->helperText('Сервис аналитики обычно сам пишет, куда положить код. Если не написано — оставьте «в <head>».'),
                Forms\Components\Textarea::make('code')
                    ->label('Код')
                    ->required()
                    ->rows(12)
                    ->extraInputAttributes(['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px;'])
                    ->helperText('Вставьте кусок кода целиком, как его выдал сервис, — вместе с <script> и <noscript>. Он попадает на страницу как есть, поэтому проверьте, что копируете код из надёжного источника.'),
                Forms\Components\Toggle::make('is_active')
                    ->label('Включён')
                    ->default(true)
                    ->helperText('Выключите, чтобы временно убрать код с сайта, не удаляя его.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Название')->searchable(),
                Tables\Columns\TextColumn::make('position')
                    ->label('Куда вставляется')
                    ->formatStateUsing(fn (string $state) => CodeSnippet::POSITIONS[$state] ?? $state),
                Tables\Columns\IconColumn::make('is_active')->label('Включён')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Изменён')
                    ->dateTime('d.m.Y H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('position')
                    ->label('Место вставки')
                    ->options(CodeSnippet::POSITIONS),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Пока пусто')
            ->emptyStateDescription('Здесь добавляют код счётчиков и метрик — он появится на всех страницах сайта.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCodeSnippets::route('/'),
            'create' => Pages\CreateCodeSnippet::route('/create'),
            'edit' => Pages\EditCodeSnippet::route('/{record}/edit'),
        ];
    }
}
