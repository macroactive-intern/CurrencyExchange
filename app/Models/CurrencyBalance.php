<?php

namespace App\Models;

use Database\Factories\CurrencyBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyBalance extends Model
{
    /** @use HasFactory<CurrencyBalanceFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'currency', 'balance'];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
