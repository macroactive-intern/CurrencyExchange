<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeLog extends Model
{
    protected $fillable = [
        'user_id',
        'from_currency',
        'to_currency',
        'from_amount',
        'to_amount',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
