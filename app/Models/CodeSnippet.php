<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Кусок кода, который вставляется в страницы витрины (счётчики, метрики, чаты).
 *
 * Код выводится на сайт без экранирования — это его смысл, — поэтому редактировать
 * раздел может только тот, у кого есть доступ в админку.
 */
class CodeSnippet extends Model
{
    /** Места вставки: подписи для админки и порядок в списке. */
    public const POSITIONS = [
        'head' => 'В <head> — перед закрытием',
        'body_start' => 'В начале <body> — сразу после открытия',
        'body_end' => 'В футере — перед закрытием </body>',
    ];

    protected $fillable = ['title', 'position', 'code', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Код читается на каждой странице сайта, поэтому лежит в кеше;
        // любое изменение в админке этот кеш сбрасывает.
        static::saved(fn () => static::forgetCache());
        static::deleted(fn () => static::forgetCache());
    }

    public static function forgetCache(): void
    {
        foreach (array_keys(self::POSITIONS) as $position) {
            Cache::forget("code_snippets:{$position}");
        }
    }

    /**
     * Готовый HTML для указанного места вставки. Пустая строка, если для него
     * ничего не добавлено, — тогда в разметке страницы не появится ни строчки.
     */
    public static function render(string $position): string
    {
        // Выкатка на сервер ручная, и код может доехать раньше миграции — тогда
        // витрина просто ничего не вставляет, а не падает. Пустоту при этом не
        // кешируем, иначе после миграции пришлось бы чистить кеш руками.
        if (! Schema::hasTable('code_snippets')) {
            return '';
        }

        return Cache::rememberForever("code_snippets:{$position}", function () use ($position) {
            return static::query()
                ->where('position', $position)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('code')
                ->implode("\n");
        });
    }
}
