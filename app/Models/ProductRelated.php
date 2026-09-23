<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Товар, вручную добавленный в блок «Смотрите ещё» другого товара. */
class ProductRelated extends Model
{
    protected $table = 'product_related';

    public $timestamps = false;

    protected $fillable = ['product_id', 'related_product_id', 'sort_order'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }
}
