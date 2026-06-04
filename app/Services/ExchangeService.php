<?php

namespace App\Services;

use App\Models\CurrencyBalance;
use App\Models\ExchangeTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExchangeService
{
    public function exchange(User $user, string $fromCurrency, string $toCurrency, float $amount): array
    {
        $this->validatePair($fromCurrency, $toCurrency);

        $amount = round($amount, 2);

        return DB::transaction(function () use ($user, $fromCurrency, $toCurrency, $amount) {
            $balances = CurrencyBalance::where('user_id', $user->id)
                ->whereIn('currency', [$fromCurrency, $toCurrency])
                ->orderBy('currency')
                ->lockForUpdate()
                ->get()
                ->keyBy('currency');

            $fromBalance = $balances[$fromCurrency]
                ?? throw new InvalidArgumentException("Balance for {$fromCurrency} not found.");
            $toBalance = $balances[$toCurrency]
                ?? throw new InvalidArgumentException("Balance for {$toCurrency} not found.");

            if ($fromBalance->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Insufficient balance.'],
                ]);
            }

            ['net' => $credited, 'fee' => $fee] = $this->breakdown($fromCurrency, $toCurrency, $amount);

            $fromBalance->decrement('balance', $amount);
            $toBalance->increment('balance', $credited);

            ExchangeTransaction::create([
                'user_id'       => $user->id,
                'from_currency' => $fromCurrency,
                'to_currency'   => $toCurrency,
                'from_amount'   => $amount,
                'to_amount'     => $credited,
                'fee_amount'    => $fee,
                'rate'          => config("exchange.rates.{$fromCurrency}_to_{$toCurrency}"),
            ]);

            return [
                'deducted' => $amount,
                'credited' => $credited,
                'fee'      => $fee,
            ];
        }, attempts: 3);
    }

    /**
     * Returns gross, fee, and net for a given exchange.
     *
     * Rate key format: {fromCurrency}_to_{toCurrency}  (e.g. gold_to_gems)
     * Fee is applied to the gross converted amount — not to the source deduction.
     *
     * Example: 100 gold, rate 0.1, fee 2.5%
     *   gross = round(100 * 0.1, 2) = 10.00
     *   fee   = round(10.00 * 0.025, 2) = 0.25
     *   net   = round(10.00 - 0.25, 2)  = 9.75
     */
    public function breakdown(string $from, string $to, float $amount): array
    {
        $rateKey = "{$from}_to_{$to}";
        $rate    = config("exchange.rates.{$rateKey}")
            ?? throw new InvalidArgumentException(
                "No exchange rate configured for {$from} → {$to}."
            );

        $amount     = round($amount, 2);
        $gross      = round($amount * $rate, 2);
        $feePercent = config('exchange.fee_percent', 0);
        $fee        = round($gross * ($feePercent / 100), 2);
        $net        = round($gross - $fee, 2);

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
