<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $fillable = ['product_id', 'path', 'thumb_path', 'alt', 'sort_order'];

    /**
     * Что показывать в каталоге и на главной: своё превью, если владелец его
     * загрузил, иначе само фото (уменьшенные копии из него делает ImageVariants).
     */
    public function previewPath(): string
    {
        return $this->thumb_path ?: $this->path;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
