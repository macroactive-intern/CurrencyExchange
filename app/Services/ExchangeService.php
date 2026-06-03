<?php

namespace App\Services;

use App\Exceptions\InsufficientBalanceException;
use App\Models\ExchangeLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ExchangeService
{
    // Conversion: 1 gold = 10 gems, 10 gems = 1 gold (integer division, remainder discarded)
    private const RATES = [
        'gold:gems' => 10,
        'gems:gold' => 0,   // handled via intdiv below
    ];

    public function exchange(User $user, string $from, string $to, int $amount): array
    {
        $this->validatePair($from, $to);

        return DB::transaction(function () use ($user, $from, $to, $amount) {
            // Acquire locks in consistent alphabetical order (gems < gold) to prevent deadlocks.
            // Every transaction locks the same two rows in the same order, so no circular wait is possible.
            $wallets = Wallet::where('user_id', $user->id)
                ->whereIn('currency', [$from, $to])
                ->orderBy('currency')   // 'gems' before 'gold' — deterministic, deadlock-safe
                ->lockForUpdate()
                ->get()
                ->keyBy('currency');

            $fromWallet = $wallets[$from]
                ?? throw new InvalidArgumentException("Wallet for {$from} not found.");
            $toWallet = $wallets[$to]
                ?? throw new InvalidArgumentException("Wallet for {$to} not found.");

            if ($fromWallet->balance < $amount) {
                throw new InsufficientBalanceException($from, $amount, $fromWallet->balance);
            }

            $toAmount = $this->convert($from, $to, $amount);

            if ($toAmount === 0) {
                throw new InvalidArgumentException(
                    "Amount too small: {$amount} {$from} yields 0 {$to}."
                );
            }

            $fromWallet->decrement('balance', $amount);
            $toWallet->increment('balance', $toAmount);

            ExchangeLog::create([
                'user_id'       => $user->id,
                'from_currency' => $from,
                'to_currency'   => $to,
                'from_amount'   => $amount,
                'to_amount'     => $toAmount,
            ]);

            return [
                'from_currency' => $from,
                'to_currency'   => $to,
                'from_amount'   => $amount,
                'to_amount'     => $toAmount,
                'balances'      => [
                    $from => $fromWallet->fresh()->balance,
                    $to   => $toWallet->fresh()->balance,
                ],
            ];
        });
    }

    public function convert(string $from, string $to, int $amount): int
    {
        $this->validatePair($from, $to);

        return match ("{$from}:{$to}") {
            'gold:gems' => $amount * 10,
            'gems:gold' => intdiv($amount, 10),
        };
    }

    private function validatePair(string $from, string $to): void
    {
        $valid = ['gold', 'gems'];

        if (! in_array($from, $valid, true) || ! in_array($to, $valid, true)) {
            throw new InvalidArgumentException("Unknown currency. Allowed: gold, gems.");
        }

        if ($from === $to) {
            throw new InvalidArgumentException("Cannot exchange a currency for itself.");
        }
    }
}
