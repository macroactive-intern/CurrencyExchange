<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => $this->faker->randomElement(['gold', 'gems']),
            'balance' => $this->faker->numberBetween(0, 1000),
        ];
    }

    public function gold(int $balance = 100): static
    {
        return $this->state(['currency' => 'gold', 'balance' => $balance]);
    }

    public function gems(int $balance = 0): static
    {
        return $this->state(['currency' => 'gems', 'balance' => $balance]);
    }
}
