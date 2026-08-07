<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $fillable = ['slug', 'title', 'subtitle', 'breadcrumb_title', 'body', 'image', 'image_mobile', 'meta_title', 'meta_description', 'template', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
