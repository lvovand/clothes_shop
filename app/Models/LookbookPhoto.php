<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LookbookPhoto extends Model
{
    protected $fillable = ['lookbook_collection_id', 'image', 'sort_order'];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(LookbookCollection::class, 'lookbook_collection_id');
    }
}
