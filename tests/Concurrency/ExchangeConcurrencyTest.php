<?php

// Concurrency tests require MySQL running and currency_exchange_test to exist.
// Do NOT wrap these tests in RefreshDatabase — spawned processes need committed data.
// Run with: php artisan test tests/Concurrency --env=testing
//
// Decimal math for 10 gold (rate 0.1, fee 2.5%):
//   gross = round(10 * 0.1, 2)        = 1.00
//   fee   = round(1.00 * 0.025, 2)    = 0.03
//   net   = round(1.00 - 0.03, 2)     = 0.97
//
// Each process spawns php artisan test:exchange via Symfony Process.
// All processes are started first, then waited on — true parallel execution.

use App\Models\CurrencyBalance;
use App\Models\ExchangeTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// ── helpers ───────────────────────────────────────────────────────────────────

function committedUser(float $gold, float $gems): User
{
    $user = User::forceCreate([
        'name'     => 'Concurrent Tester ' . Str::random(6),
        'email'    => 'concurrent+' . Str::random(8) . '@test.local',
        'password' => bcrypt('password'),
    ]);

    CurrencyBalance::insert([
        ['user_id' => $user->id, 'currency' => 'gold', 'balance' => $gold,
         'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $user->id, 'currency' => 'gems', 'balance' => $gems,
         'created_at' => now(), 'updated_at' => now()],
    ]);

    return $user;
}

function cleanupConcurrentUser(User $user): void
{
    DB::table('exchange_transactions')->where('user_id', $user->id)->delete();
    DB::table('currency_balances')->where('user_id', $user->id)->delete();
    DB::table('users')->where('id', $user->id)->delete();
}

/**
 * Launch $count parallel processes each running test:exchange and wait for all to finish.
 * Returns the array of completed Process objects.
 */
function launchExchangeProcesses(int $userId, string $from, string $to, float $amount, int $count): array
{
    $processes = [];

    for ($i = 0; $i < $count; $i++) {
        $process = new Process([
            PHP_BINARY,
            base_path('artisan'),
            'test:exchange',
            (string) $userId,
            $from,
            $to,
            (string) $amount,
            '--env=testing',
        ]);
        $process->setTimeout(60);
        $process->start();
        $processes[] = $process;
    }

    foreach ($processes as $process) {
        $process->wait();
    }

    return $processes;
}

// ── tests ─────────────────────────────────────────────────────────────────────

it('50 concurrent exchanges all succeed when balance is sufficient', function () {
    // 1000 gold ÷ 10 per exchange = 100 slots; 50 processes → all succeed.
    // Gold: 1000.00 − (50 × 10) = 500.00
    // Gems: 50 × 0.97            = 48.50
    $user = committedUser(gold: 1000.00, gems: 0.00);

    launchExchangeProcesses($user->id, 'gold', 'gems', 10, 50);

    $goldBalance = CurrencyBalance::where('user_id', $user->id)->where('currency', 'gold')->first();
    $gemsBalance = CurrencyBalance::where('user_id', $user->id)->where('currency', 'gems')->first();

    expect($goldBalance->balance)->toBe('500.00');
    expect($gemsBalance->balance)->toBe('48.50');
    expect($goldBalance->balance)->toBeGreaterThanOrEqual(0);
    expect($gemsBalance->balance)->toBeGreaterThanOrEqual(0);
    expect(ExchangeTransaction::where('user_id', $user->id)->count())->toBe(50);

    cleanupConcurrentUser($user);
});

it('only 10 of 50 concurrent exchanges succeed — proves no double-spend', function () {
    // 100 gold ÷ 10 per exchange = exactly 10 can succeed.
    // The remaining 40 processes must fail with insufficient balance.
    // Gold must reach 0.00 and must never go negative.
    // Gems = 10 successful × 0.97 = 9.70.
    // Transaction count must be exactly 10 (no phantom writes on failure).
    $user = committedUser(gold: 100.00, gems: 0.00);

    launchExchangeProcesses($user->id, 'gold', 'gems', 10, 50);

    $goldBalance = CurrencyBalance::where('user_id', $user->id)->where('currency', 'gold')->first();
    $gemsBalance = CurrencyBalance::where('user_id', $user->id)->where('currency', 'gems')->first();

    expect($goldBalance->balance)->toBe('0.00');
    expect($gemsBalance->balance)->toBe('9.70');
    expect($goldBalance->balance)->toBeGreaterThanOrEqual(0);
    expect($gemsBalance->balance)->toBeGreaterThanOrEqual(0);
    expect(ExchangeTransaction::where('user_id', $user->id)->count())->toBe(10);

    cleanupConcurrentUser($user);
});
