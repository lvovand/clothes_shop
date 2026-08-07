<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageSlide extends Model
{
    protected $fillable = ['device', 'image', 'link_url', 'link_text', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeDesktop($query)
    {
        return $query->where('device', 'desktop');
    }

    public function scopeMobile($query)
    {
        return $query->where('device', 'mobile');
    }
}
