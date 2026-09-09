<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** Адрес раздела: у виртуальной категории (ALL) это весь каталог, а не /catalog/{slug}. */
    public function url(): string
    {
        return $this->is_virtual ? route('catalog.all') : route('catalog.category', $this);
    }

    protected $fillable = [
        'is_virtual', 'slug', 'name', 'image', 'thumb_path', 'sort_order', 'is_active',
        'is_private', 'access_code',
        'launch_at', 'teaser_slug', 'teaser_title', 'teaser_lead', 'teaser_body',
        'teaser_image', 'teaser_image_mobile', 'show_in_menu', 'menu_label',
        'meta_title', 'meta_description', 'seo_text',
    ];

    /** Превью раздела: своё, если загружено, иначе основная картинка. */
    public function previewPath(): ?string
    {
        return $this->thumb_path ?: $this->image;
    }

    protected $casts = [
        'is_active' => 'boolean',
        'is_virtual' => 'boolean',
        'is_private' => 'boolean',
        'show_in_menu' => 'boolean',
        'launch_at' => 'datetime',
    ];

    /**
     * Закрыт ли раздел прямо сейчас. Закрытым он остаётся до времени запуска, а
     * когда оно наступило — становится обычным сам, без крона и ручного тумблера:
     * состояние всегда выводится из текущего времени.
     */
    public function scopeLockedNow($query)
    {
        return $query->where('is_private', true)
            ->where(fn ($q) => $q->whereNull('launch_at')->orWhere('launch_at', '>', now()));
    }

    /**
     * Только открытые разделы: закрытых нет ни в меню и плитках, ни в карте сайта
     * и фидах — попасть в них можно лишь по прямой ссылке с промокодом.
     */
    public function scopeNotPrivate($query)
    {
        return $query->whereNot(fn ($q) => $q->lockedNow());
    }

    /** Наступило ли время запуска коллекции. Раздел без даты не запускается никогда. */
    public function isLaunched(): bool
    {
        return $this->launch_at !== null && $this->launch_at->isPast();
    }

    /** Закрыт ли раздел сейчас (то же правило, что и в scopeLockedNow). */
    public function isLockedNow(): bool
    {
        return (bool) $this->is_private && ! $this->isLaunched();
    }

    /** Ждёт ли раздел запуска — только для таких показывается страница с отсчётом. */
    public function awaitsLaunch(): bool
    {
        return $this->is_private && $this->launch_at !== null && $this->launch_at->isFuture();
    }

    /** Адрес страницы ожидания. Пусто, если адрес не задан в админке. */
    public function teaserUrl(): ?string
    {
        return $this->teaser_slug ? url('/'.$this->teaser_slug) : null;
    }

    /**
     * Куда вести посетителя: до запуска — на страницу с отсчётом (если она заведена),
     * после — сразу в раздел. Отсюда пункт меню сам меняет назначение в момент старта.
     */
    public function publicUrl(): string
    {
        return ($this->awaitsLaunch() ? $this->teaserUrl() : null) ?? $this->url();
    }

    /** Ближайший запуск коллекции — по нему укорачивается TTL кэша карты сайта и фидов. */
    public static function nextLaunchAt(): ?\Illuminate\Support\Carbon
    {
        $next = static::query()
            ->where('is_active', true)
            ->where('is_private', true)
            ->whereNotNull('launch_at')
            ->where('launch_at', '>', now())
            ->min('launch_at');

        return $next ? \Illuminate\Support\Carbon::parse($next) : null;
    }

    /**
     * TTL кэша карты сайта и фидов, укороченный до ближайшего запуска коллекции:
     * иначе открывшийся раздел попал бы туда только через час. Ниже минуты не
     * опускаемся — чтобы момент старта не превратился в поток пересборок.
     */
    public static function cacheTtl(int $default): int
    {
        $next = static::nextLaunchAt();

        if (! $next) {
            return $default;
        }

        return max(60, min($default, (int) now()->diffInSeconds($next, false)));
    }

    /** Совпадает ли введённый покупателем промокод с кодом раздела. */
    public function accessCodeMatches(?string $code): bool
    {
        $expected = trim((string) $this->access_code);

        return $expected !== '' && mb_strtolower(trim((string) $code)) === mb_strtolower($expected);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Products whose canonical/primary category is this one. */
    public function primaryProducts(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** All products listed under this category (real many-to-many). */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }
}
