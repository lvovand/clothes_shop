<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'slug', 'name', 'category_id', 'is_new', 'status', 'sort_order',
        'meta_title', 'meta_description',
        'weight_kg', 'length_cm', 'width_cm', 'height_cm',
    ];

    protected $casts = [
        'is_new' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Primary/canonical category (used for breadcrumbs and the canonical URL). */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** All categories this product is listed under (a product can belong to several at once). */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function contentBlocks(): HasMany
    {
        return $this->hasMany(ProductContentBlock::class)->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Товары, которым место в общих списках витрины: каталог, поиск, новинки,
     * карта сайта, товарные фиды. Привязка к закрытой категории убирает товар
     * отовсюду — он виден только внутри самой категории, после промокода.
     */
    public function scopeListedPublicly($query)
    {
        return $query->published()
            ->whereDoesntHave('categories', fn ($q) => $q->lockedNow());
    }

    /** Лежит ли товар хотя бы в одной закрытой категории. */
    public function isPrivate(): bool
    {
        return $this->categories()->lockedNow()->exists();
    }

    /** Закрытые категории, в которых лежит товар. */
    public function privateCategories()
    {
        return $this->categories()->lockedNow()->get();
    }

    /** Товар из закрытой категории — только для просмотра, купить его нельзя. */
    public function isPurchasable(): bool
    {
        return ! $this->isPrivate();
    }

    public function minPrice(): ?float
    {
        return $this->variants->map(fn (Variant $v) => $v->currentPrice())->min();
    }
}
