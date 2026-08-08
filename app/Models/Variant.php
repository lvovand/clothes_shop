<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variant extends Model
{
    protected $fillable = ['product_id', 'sku', 'regular_price', 'sale_price', 'stock_qty', 'image_id'];

    protected $casts = [
        'regular_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    public function attributeValues(): BelongsToMany
    {
        return $this->belongsToMany(AttributeValue::class, 'variant_attribute_values');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(VariantStock::class);
    }

    /** Остаток на конкретном складе. */
    public function stockAt(int $warehouseId): int
    {
        return (int) $this->stocks->firstWhere('warehouse_id', $warehouseId)?->qty;
    }

    public function currentPrice(): float
    {
        return (float) ($this->sale_price ?? $this->regular_price);
    }

    public function isOnSale(): bool
    {
        return $this->sale_price !== null && (float) $this->sale_price < (float) $this->regular_price;
    }

    /**
     * Есть ли товар хоть на одном складе. `stock_qty` — суммарный кеш по складам,
     * его пересчитывает App\Services\StockService (руками не менять).
     */
    public function inStock(): bool
    {
        return $this->stock_qty > 0;
    }
}
