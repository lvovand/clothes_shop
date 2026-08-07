<?php

namespace App\Filament\Pages;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page as ContentPage;
use Filament\Actions\CreateAction;
use SolutionForest\FilamentTree\Actions\EditAction as TreeEditAction;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\FilamentTree\Pages\TreePage as BasePage;

/**
 * Одно меню сайта деревом: пункты перетаскиваются мышью, вложенные видны отступом.
 *
 * У каждого меню своя страница — в общем дереве пункты шапки и обеих колонок
 * футера шли вперемешку и понять структуру было нельзя.
 */
abstract class MenuTreeBase extends BasePage
{
    /** Ключ меню в таблице menus. */
    protected static string $menuKey = 'primary';
    protected static string $model = MenuItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-bars-3';

    protected static ?string $navigationGroup = 'Контент';

    /** Шапка → SHOP → категория: глубже вложенности на сайте не бывает. */
    protected static int $maxDepth = 2;

    public function getTreeRecordTitle(?Model $record = null): string
    {
        return $record?->label ?? '';
    }

    /** id меню этой страницы: ключ (`primary`/`footer`/`informations`) → строка в `menus`. */
    protected static function menuId(): ?int
    {
        return Menu::where('key', static::$menuKey)->value('id');
    }

    /**
     * Дерево одной страницы — только пункты своего меню. Без этого пакет отдаёт
     * `MenuItem::query()` целиком, и все три страницы показывают одно и то же:
     * пункты шапки и обеих колонок футера вперемешку.
     */
    protected function getTreeQuery(): Builder
    {
        return MenuItem::query()->where('menu_id', static::menuId());
    }

    /**
     * Форма пункта в модалке «Создать» / «Изменить». Пакет без неё показывает
     * пустое окно с одной кнопкой — завести пункт нельзя.
     *
     * Меню не спрашиваем: страница уже задаёт его, поле скрытое со значением
     * своей страницы — иначе новый пункт уехал бы в чужое меню.
     */
    protected function getFormSchema(): array
    {
        return [
            Forms\Components\Hidden::make('menu_id')
                ->default(fn () => static::menuId()),
            Forms\Components\TextInput::make('label')
                ->label('Подпись')
                ->required()
                ->helperText('Текст, который видит покупатель.'),
            Forms\Components\Select::make('parent_id')
                ->label('Вложен в пункт')
                ->options(fn (?MenuItem $record) => MenuItem::query()
                    ->where('menu_id', static::menuId())
                    ->whereNull('parent_id')
                    ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                    ->pluck('label', 'id'))
                ->placeholder('— пункт верхнего уровня —')
                ->helperText('Например, категории вложены в пункт SHOP.'),
            Forms\Components\Select::make('link_type')
                ->label('Куда ведёт')
                ->options([
                    'url' => 'Произвольный адрес',
                    Category::class => 'Категорию каталога',
                    ContentPage::class => 'Страницу сайта',
                ])
                ->default('url')
                ->live()
                ->dehydrated(false)
                // Тип ссылки хранится в `linkable_type`, отдельного поля в таблице нет:
                // при открытии формы восстанавливаем его из записи.
                ->afterStateHydrated(fn (Forms\Components\Select $component, ?MenuItem $record) => $component->state($record?->linkable_type ?: 'url'))
                ->helperText('Для категории и страницы адрес подставляется сам и остаётся верным при смене ссылки.'),
            Forms\Components\TextInput::make('url')
                ->label('Адрес')
                ->visible(fn (Forms\Get $get) => $get('link_type') === 'url')
                ->helperText('Можно относительный, например /lookbook'),
            Forms\Components\Select::make('linkable_id')
                ->label('Категория')
                ->options(fn () => Category::orderBy('name')->pluck('name', 'id'))
                ->searchable()
                ->visible(fn (Forms\Get $get) => $get('link_type') === Category::class)
                ->required(fn (Forms\Get $get) => $get('link_type') === Category::class),
            Forms\Components\Select::make('linkable_id')
                ->label('Страница')
                ->options(fn () => ContentPage::orderBy('title')->pluck('title', 'id'))
                ->searchable()
                ->visible(fn (Forms\Get $get) => $get('link_type') === ContentPage::class)
                ->required(fn (Forms\Get $get) => $get('link_type') === ContentPage::class),
            Forms\Components\Hidden::make('linkable_type')
                ->dehydrateStateUsing(fn (Forms\Get $get) => $get('link_type') === 'url' ? null : $get('link_type')),
            Forms\Components\Toggle::make('is_active')
                ->label('Показывать на сайте')
                ->default(true)
                ->helperText('Пункт, ведущий на выключенную категорию или страницу, скрывается и сам, независимо от этого тумблера.'),
        ];
    }

    /**
     * Причина, по которой пункта нет на сайте — второй строкой под подписью: там
     * пакет рисует её мелко и приглушённо, а в самой подписи она мешала читать меню.
     */
    public function getTreeRecordDescription(?Model $record = null): ?string
    {
        if (! $record) {
            return null;
        }

        if (! $record->is_active) {
            return 'скрыт';
        }

        // Пункт может быть выключен не сам, а из-за категории или страницы, на
        // которую ведёт: на сайте его тогда тоже нет.
        if (! $record->isVisible()) {
            return 'не показан: раздел выключен в каталоге';
        }

        return null;
    }

    /**
     * У скрытых пунктов — перечёркнутый глаз слева: приглушить строку целиком
     * пакет не даёт, а по иконке скрытые видно с одного взгляда.
     */
    public function getTreeRecordIcon(?Model $record = null): ?string
    {
        return $record && ! $record->isVisible() ? 'heroicon-o-eye-slash' : null;
    }

    /**
     * Заплатка к багу пакета: `HasActions::mountedTreeActionShouldOpenModal()` зовёт
     * табличный метод `mountedTableActionHasForm`, которого у страницы дерева нет —
     * любое своё действие у узла падало с 500. Правим не vendor (его перезапишет
     * composer), а подставляем недостающий метод, проксируя на «древесный» аналог.
     */
    public function mountedTableActionHasForm(): bool
    {
        return $this->mountedTreeActionHasForm();
    }

    /**
     * Кнопка «показать/скрыть» прямо в дереве: пункт чаще нужно временно убрать с
     * сайта, а не удалять — удалённый пришлось бы заводить заново со всеми детьми.
     */
    protected function getTreeActions(): array
    {
        return array_merge([
            \SolutionForest\FilamentTree\Actions\Action::make('toggleActive')
                // Только иконка: подпись рядом с карандашом и корзиной занимала пол-строки.
                ->iconButton()
                ->tooltip(fn ($record) => $record?->is_active ? 'Скрыть с сайта' : 'Показать на сайте')
                ->icon(fn ($record) => $record?->is_active ? 'heroicon-o-eye' : 'heroicon-o-eye-slash')
                // Серый глаз и у пункта, скрытого выключенной категорией: на сайте его нет,
                // сколько бы ни было включено у самого пункта.
                ->color(fn ($record) => $record && $record->isVisible() ? 'success' : 'gray')
                // Запись приходит параметром $record без тип-хинта: с указанным классом
                // контейнер пытается разрешить его как зависимость и подставляет null.
                ->action(fn ($record) => $record?->update(['is_active' => ! $record->is_active])),
        ], parent::getTreeActions());
    }

    /** Пакет подписывает модалку служебным именем модели («Создать menu item»). */
    protected function afterConfiguredCreateAction(CreateAction $action): CreateAction
    {
        return $action->label('Создать пункт')->modalHeading('Новый пункт меню')->modalSubmitActionLabel('Создать');
    }

    protected function afterConfiguredEditAction(TreeEditAction $action): TreeEditAction
    {
        return $action->modalHeading('Пункт меню')->modalSubmitActionLabel('Сохранить');
    }

    protected function hasDeleteAction(): bool
    {
        return true;
    }

    protected function hasEditAction(): bool
    {
        return true;
    }

    protected function hasViewAction(): bool
    {
        return false;
    }
}
