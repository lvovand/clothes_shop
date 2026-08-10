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

    protected $fillable = ['is_virtual', 'slug', 'name', 'image', 'thumb_path', 'sort_order', 'is_active'];

    /** Превью раздела: своё, если загружено, иначе основная картинка. */
    public function previewPath(): ?string
    {
        return $this->thumb_path ?: $this->image;
    }

    protected $casts = [
        'is_active' => 'boolean',
        'is_virtual' => 'boolean',
    ];

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
