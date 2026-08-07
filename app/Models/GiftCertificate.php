<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCertificate extends Model
{
    protected $fillable = [
        'code', 'initial_amount', 'remaining_balance',
        'recipient_name', 'recipient_email',
        'buyer_name', 'buyer_email', 'buyer_phone',
        'message', 'status', 'payment_id',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftCertificateRedemption::class);
    }
}
