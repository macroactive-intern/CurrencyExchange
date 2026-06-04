<?php

// Concurrency tests require MySQL to be running and currency_exchange_test to exist.
// They do NOT use RefreshDatabase. Each test commits its own setup rows so that
// the spawned artisan processes (separate PHP processes) can read them.
// Run with: php artisan test tests/Concurrency --env=testing
//
// Net rate (gold→gems): round(round(amount*0.1,2) - round(gross*0.025,2), 2) per exchange
//   20 gold → 1.95 gems (gross=2.00, fee=0.05, net=1.95; effective rate 0.0975)
//   10 gold → 0.97 gems (gross=1.00, fee=0.03, net=0.97; effective rate 0.097)
// Net rate (gems→gold): round(8.0 * 0.975 * amount, 2) per exchange
//   10 gems → 78 gold (rate 7.8 holds exactly)

use App\Models\CurrencyBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Insert a user + two currency balances and commit immediately so that
 * spawned artisan processes can see the rows.
 */
function createCommittedUser(int $gold = 0, int $gems = 0): User
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

/**
 * Remove all rows created by a concurrency test.
 */
function cleanUpUser(User $user): void
{
    DB::table('exchange_transactions')->where('user_id', $user->id)->delete();
    DB::table('currency_balances')->where('user_id', $user->id)->delete();
    DB::table('users')->where('id', $user->id)->delete();
}

/**
 * Spawn $n artisan processes each trying to exchange $amount $from → $to.
 * Returns an array of exit codes.
 */
function spawnExchanges(int $userId, string $from, string $to, int $amount, int $n): array
{
    $artisan = base_path('artisan');
    $php     = PHP_BINARY;

    $procs = $pipes = [];

    for ($i = 0; $i < $n; $i++) {
        $procs[] = proc_open(
            "{$php} {$artisan} exchange:run {$userId} {$from} {$to} {$amount} --env=testing",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$i]
        );
    }

    $exitCodes = [];
    foreach ($procs as $i => $proc) {
        fclose($pipes[$i][0]);
        fclose($pipes[$i][1]);
        fclose($pipes[$i][2]);
        $exitCodes[] = proc_close($proc);
    }

    return $exitCodes;
}

// ── tests ─────────────────────────────────────────────────────────────────────

it('handles 10 concurrent gold→gems exchanges and never goes negative', function () {
    // User starts with 100 gold. 10 processes each try to exchange 20 gold.
    // Only 5 can succeed (100 / 20 = 5). The other 5 must fail cleanly.
    // Conservation: gems_earned = gold_deducted * 0.0975 (exact for 20-gold chunks)
    $user = createCommittedUser(gold: 100, gems: 0);

    spawnExchanges($user->id, 'gold', 'gems', 20, 10);

    $goldBalance = (float) CurrencyBalance::where('user_id', $user->id)->where('currency', 'gold')->value('balance');
    $gemsBalance = (float) CurrencyBalance::where('user_id', $user->id)->where('currency', 'gems')->value('balance');

    expect($goldBalance)->toBeGreaterThanOrEqual(0.0);
    expect($gemsBalance)->toBeGreaterThanOrEqual(0.0);

    // Every deducted 20-gold unit must have produced exactly 1.95 gems (round(2.0*0.975,2))
    $goldDeducted = 100.0 - $goldBalance;
    expect(round($gemsBalance, 6))->toBe(round($goldDeducted * 0.0975, 6));

    cleanUpUser($user);
});

it('all 5 concurrent exchanges succeed when balance is exactly enough', function () {
    // User starts with 100 gold. 5 processes each exchange 20 gold.
    // All 5 must succeed — total 100 gold consumed, 9.75 gems earned (5 × 1.95).
    $user = createCommittedUser(gold: 100, gems: 0);

    $exitCodes = spawnExchanges($user->id, 'gold', 'gems', 20, 5);

    $goldBalance = (float) CurrencyBalance::where('user_id', $user->id)->where('currency', 'gold')->value('balance');
    $gemsBalance = (float) CurrencyBalance::where('user_id', $user->id)->where('currency', 'gems')->value('balance');

    expect($goldBalance)->toBe(0.0);
    expect(round($gemsBalance, 6))->toBe(9.75);  // 5 × 1.95
    expect(collect($exitCodes)->filter(fn ($c) => $c === 0)->count())->toBe(5);

    cleanUpUser($user);
});

it('locks in consistent order so opposite-direction exchanges do not deadlock', function () {
    // Two users simultaneously exchange in opposite directions (gold→gems and gems→gold).
    // This would deadlock without consistent lock ordering (gems locked before gold always).
    $userA = createCommittedUser(gold: 50, gems: 0);
    $userB = createCommittedUser(gold: 0, gems: 50);

    $php     = PHP_BINARY;
    $artisan = base_path('artisan');

    $procsA = $pipesA = [];
    $procsB = $pipesB = [];

    for ($i = 0; $i < 5; $i++) {
        $procsA[] = proc_open(
            "{$php} {$artisan} exchange:run {$userA->id} gold gems 10 --env=testing",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesA[$i]
        );
        $procsB[] = proc_open(
            "{$php} {$artisan} exchange:run {$userB->id} gems gold 10 --env=testing",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB[$i]
        );
    }

    foreach (array_merge($procsA, $procsB) as $i => $proc) {
        proc_close($proc);
    }

    $goldA = (float) CurrencyBalance::where('user_id', $userA->id)->where('currency', 'gold')->value('balance');
    $gemsA = (float) CurrencyBalance::where('user_id', $userA->id)->where('currency', 'gems')->value('balance');
    $goldB = (float) CurrencyBalance::where('user_id', $userB->id)->where('currency', 'gold')->value('balance');
    $gemsB = (float) CurrencyBalance::where('user_id', $userB->id)->where('currency', 'gems')->value('balance');

    expect($goldA)->toBeGreaterThanOrEqual(0.0);
    expect($gemsA)->toBeGreaterThanOrEqual(0.0);
    expect($goldB)->toBeGreaterThanOrEqual(0.0);
    expect($gemsB)->toBeGreaterThanOrEqual(0.0);

    // Conservation for A: each 10-gold exchange credits round(1.00-0.03,2)=0.97 gems
    $goldDeductedA = 50.0 - $goldA;
    $successesA = (int) round($goldDeductedA / 10);
    expect(round($gemsA, 6))->toBe(round($successesA * 0.97, 6));

    // Conservation for B: each 10-gem exchange credits round(78,2)=78 gold
    $gemsDeductedB = 50.0 - $gemsB;
    expect(round($goldB, 6))->toBe(round($gemsDeductedB * 7.8, 6));

    cleanUpUser($userA);
    cleanUpUser($userB);
});
