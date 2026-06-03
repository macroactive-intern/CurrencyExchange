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
    public function exchange(User $user, string $from, string $to, float $amount): array
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

            ['net' => $toAmount, 'fee' => $fee] = $this->breakdown($from, $to, $amount);

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
                'fee'           => $fee,
                'balances'      => [
                    $from => $fromWallet->fresh()->balance,
                    $to   => $toWallet->fresh()->balance,
                ],
            ];
        });
    }

    /**
     * Returns gross, fee, and net for a given exchange.
     *
     * Rate key format: {fromCurrency}_to_{toCurrency}  (e.g. gold_to_gems)
     * Fee is applied to the gross converted amount — not to the source deduction.
     *
     * Example: 100 gold, rate 0.1, fee 2.5%
     *   gross = 100 * 0.1 = 10 gems
     *   fee   = 10 * 0.025 = 0.25 gems
     *   net   = 10 - 0.25  = 9.75 gems
     */
    public function breakdown(string $from, string $to, float $amount): array
    {
        $rateKey = "{$from}_to_{$to}";
        $rate    = config("exchange.rates.{$rateKey}")
            ?? throw new InvalidArgumentException(
                "No exchange rate configured for {$from} → {$to}."
            );

        $gross      = $amount * $rate;
        $feePercent = config('exchange.fee_percent', 0);
        $fee        = $gross * ($feePercent / 100);
        $net        = $gross - $fee;

        return ['gross' => $gross, 'fee' => $fee, 'net' => $net];
    }

    public function convert(string $from, string $to, float $amount): float
    {
        $this->validatePair($from, $to);

        return $this->breakdown($from, $to, $amount)['net'];
    }

    private function validatePair(string $from, string $to): void
    {
        $valid = array_unique(
            array_merge(
                array_map(fn ($key) => explode('_to_', $key)[0], array_keys(config('exchange.rates', []))),
                array_map(fn ($key) => explode('_to_', $key)[1], array_keys(config('exchange.rates', [])))
            )
        );

        if (! in_array($from, $valid, true) || ! in_array($to, $valid, true)) {
            throw new InvalidArgumentException("Unknown currency. Allowed: " . implode(', ', $valid) . ".");
        }

        if ($from === $to) {
            throw new InvalidArgumentException("Cannot exchange a currency for itself.");
        }
    }
}
