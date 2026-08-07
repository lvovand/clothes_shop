<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SolutionForest\FilamentTree\Concern\ModelTree;

class MenuItem extends Model
{
    // Дерево пунктов в админке (страница «Меню сайта»). Пакет по умолчанию ждёт
    // колонки title/order/parent_id и корень с parent_id = -1 — у нас имена свои,
    // а корневой пункт хранится с NULL, иначе внешний ключ на самого себя не сойдётся.
    use ModelTree;

    public function determineTitleColumnName(): string
    {
        return 'label';
    }

    public function determineOrderColumnName(): string
    {
        return 'sort_order';
    }

    public function determineParentColumnName(): string
    {
        return 'parent_id';
    }

    public static function defaultParentKey()
    {
        return null;
    }

    protected $fillable = ['menu_id', 'parent_id', 'label', 'url', 'linkable_type', 'linkable_id', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('sort_order');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Пункт не показывается, если выключен сам либо ведёт на отключённый в
     * админке раздел или страницу: выключатель у категории обязан убирать её и из
     * меню, иначе ссылка ведёт на 404.
     */
    public function isVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match (true) {
            $this->linkable instanceof Category => (bool) $this->linkable->is_active,
            $this->linkable instanceof Page => (bool) $this->linkable->is_active,
            default => true,
        };
    }

    public function resolvedUrl(): string
    {
        return match (true) {
            $this->linkable instanceof Category => $this->linkable->url(),
            $this->linkable instanceof Page => route('page.show', $this->linkable->slug),
            default => $this->url ?? '#',
        };
    }
}
