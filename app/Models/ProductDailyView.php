<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Число просмотров карточки товара за день. */
class ProductDailyView extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['product_id', 'date', 'views'];
}
