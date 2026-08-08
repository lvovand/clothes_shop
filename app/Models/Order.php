<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'status', 'customer_name', 'customer_phone', 'customer_email',
        'shipping_method_id', 'shipping_address', 'shipping_cost',
        'payment_method', 'payment_status', 'subtotal', 'discount_total', 'coupon_code',
        'gift_certificate_code', 'gift_certificate_used', 'total', 'comment',
        'stock_returned_at',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'stock_returned_at' => 'datetime',
    ];

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
