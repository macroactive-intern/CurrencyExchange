<?php

// Concurrency tests require MySQL to be running and currency_exchange_test to exist.
// They do NOT use RefreshDatabase. Each test commits its own setup rows so that
// the spawned artisan processes (separate PHP processes) can read them.
// Run with: php artisan test tests/Concurrency --env=testing

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ── helpers ───────────────────────────────────────────────────────────────────

/**
 * Insert a user + two wallets and commit immediately so that
 * spawned artisan processes can see the rows.
 */
function createCommittedUser(int $gold = 0, int $gems = 0): User
{
    $user = User::forceCreate([
        'name'     => 'Concurrent Tester ' . Str::random(6),
        'email'    => 'concurrent+' . Str::random(8) . '@test.local',
        'password' => bcrypt('password'),
    ]);

    Wallet::insert([
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
    DB::table('exchange_logs')->where('user_id', $user->id)->delete();
    DB::table('wallets')->where('user_id', $user->id)->delete();
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
    $user = createCommittedUser(gold: 100, gems: 0);

    spawnExchanges($user->id, 'gold', 'gems', 20, 10);

    $goldBalance = Wallet::where('user_id', $user->id)->where('currency', 'gold')->value('balance');
    $gemsBalance = Wallet::where('user_id', $user->id)->where('currency', 'gems')->value('balance');

    // Balance must never go negative and every deducted gold must equal gained gems / 10
    expect($goldBalance)->toBeGreaterThanOrEqual(0);
    expect($gemsBalance)->toBeGreaterThanOrEqual(0);

    // Each successful exchange: 20 gold → 200 gems, so gold + gems/10 must equal 100
    expect($goldBalance + intdiv($gemsBalance, 10))->toBe(100);

    cleanUpUser($user);
});

it('all 5 concurrent exchanges succeed when balance is exactly enough', function () {
    // User starts with 100 gold. 5 processes each exchange 20 gold.
    // All 5 must succeed — total 100 gold consumed, 1000 gems earned.
    $user = createCommittedUser(gold: 100, gems: 0);

    $exitCodes = spawnExchanges($user->id, 'gold', 'gems', 20, 5);

    $goldBalance = Wallet::where('user_id', $user->id)->where('currency', 'gold')->value('balance');
    $gemsBalance = Wallet::where('user_id', $user->id)->where('currency', 'gems')->value('balance');

    expect($goldBalance)->toBe(0);
    expect($gemsBalance)->toBe(1000);
    expect(collect($exitCodes)->filter(fn ($c) => $c === 0)->count())->toBe(5);

    cleanUpUser($user);
});

it('locks in consistent order so opposite-direction exchanges do not deadlock', function () {
    // Two users simultaneously exchange in opposite directions (gold→gems and gems→gold).
    // This would deadlock without consistent lock ordering.
    $userA = createCommittedUser(gold: 50, gems: 0);
    $userB = createCommittedUser(gold: 0, gems: 500);

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
            "{$php} {$artisan} exchange:run {$userB->id} gems gold 50 --env=testing",
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipesB[$i]
        );
    }

    foreach (array_merge($procsA, $procsB) as $i => $proc) {
        proc_close($proc);
    }

    // Both users' balances must be internally consistent — no negative balances
    $goldA = Wallet::where('user_id', $userA->id)->where('currency', 'gold')->value('balance');
    $gemsA = Wallet::where('user_id', $userA->id)->where('currency', 'gems')->value('balance');
    $goldB = Wallet::where('user_id', $userB->id)->where('currency', 'gold')->value('balance');
    $gemsB = Wallet::where('user_id', $userB->id)->where('currency', 'gems')->value('balance');

    expect($goldA)->toBeGreaterThanOrEqual(0);
    expect($gemsA)->toBeGreaterThanOrEqual(0);
    expect($goldB)->toBeGreaterThanOrEqual(0);
    expect($gemsB)->toBeGreaterThanOrEqual(0);

    // Conservation: original 50 gold for A must equal goldA + gemsA/10
    expect($goldA + intdiv($gemsA, 10))->toBe(50);

    cleanUpUser($userA);
    cleanUpUser($userB);
});
