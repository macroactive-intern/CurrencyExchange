<?php

namespace Database\Factories;

use App\Models\CurrencyBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyBalance>
 */
class CurrencyBalanceFactory extends Factory
{
    protected $model = CurrencyBalance::class;

    public function definition(): array
    {
        return [
            'user_id'  => User::factory(),
            'currency' => 'gold',
            'balance'  => 1000.00,
        ];
    }

    public function gold(float $balance = 1000): static
    {
        return $this->state(['currency' => 'gold', 'balance' => $balance]);
    }

    public function gems(float $balance = 0): static
    {
        return $this->state(['currency' => 'gems', 'balance' => $balance]);
    }

    public function currency(string $currency, float $balance): static
    {
        return $this->state(['currency' => $currency, 'balance' => $balance]);
    }
}
