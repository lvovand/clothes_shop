<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $fillable = ['code', 'name'];

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }
}
