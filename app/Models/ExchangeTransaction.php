<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'from_currency',
        'to_currency',
        'from_amount',
        'to_amount',
        'fee_amount',
        'rate',
    ];

    protected $casts = [
        'from_amount' => 'decimal:8',
        'to_amount'   => 'decimal:8',
        'fee_amount'  => 'decimal:8',
        'rate'        => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
