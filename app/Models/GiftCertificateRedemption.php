<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCertificateRedemption extends Model
{
    protected $fillable = ['gift_certificate_id', 'order_id', 'amount_used'];

    public function giftCertificate(): BelongsTo
    {
        return $this->belongsTo(GiftCertificate::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
