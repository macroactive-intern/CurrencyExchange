<?php

// Config under test:  gold_to_gems = 0.1,  gems_to_gold = 8.0,  fee_percent = 2.5
// Net multiplier   :  1 - 2.5/100 = 0.975
//
// gold → gems:  amount * 0.1  * 0.975  (e.g. 10 gold → 0.975 gems credited, 0.025 fee)
// gems → gold:  amount * 8.0  * 0.975  (e.g. 30 gems → 234 gold credited, 6 gold fee)

use App\Models\ExchangeLog;
use App\Models\User;
use App\Models\Wallet;

// ── helpers ──────────────────────────────────────────────────────────────────

function userWithWallets(int $gold = 100, int $gems = 0): User
{
    $user = User::factory()->create();
    Wallet::factory()->gold($gold)->for($user)->create();
    Wallet::factory()->gems($gems)->for($user)->create();

    return $user;
}

// ── auth ─────────────────────────────────────────────────────────────────────

it('rejects unauthenticated requests with 401', function () {
    $this->postJson('/api/exchange', [
        'from_currency' => 'gold',
        'to_currency'   => 'gems',
        'amount'        => 10,
    ])->assertUnauthorized();
});

// ── validation ────────────────────────────────────────────────────────────────

it('rejects an unsupported from-currency with an unsupported-pair error', function () {
    $user = userWithWallets();

    // silver_to_gems has no rate in config → after() fires, error on from_currency
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'silver',
            'to_currency'   => 'gems',
            'amount'        => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from_currency');
});

it('rejects an unsupported to-currency with an unsupported-pair error', function () {
    $user = userWithWallets();

    // gold_to_dust has no rate in config → after() fires, error on from_currency
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'dust',
            'amount'        => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from_currency');
});

it('rejects same-currency exchanges', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gold',
            'amount'        => 10,
        ])
        ->assertUnprocessable();
});

it('rejects a zero amount', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

it('rejects a negative amount', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => -5,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

// ── happy paths ───────────────────────────────────────────────────────────────

it('exchanges gold for gems and returns deducted, credited, fee', function () {
    // 10 gold → gross 1.0 gem → fee 0.025 → credited 0.975 gems
    $user = userWithWallets(gold: 50, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 10,
        ])
        ->assertOk()
        ->assertJson([
            'deducted' => 10,
            'credited' => 0.975,
            'fee'      => 0.025,
        ]);
});

it('exchanges gems for gold and returns deducted, credited, fee', function () {
    // 30 gems → gross 240 gold → fee 6 → credited 234 gold
    $user = userWithWallets(gold: 0, gems: 50);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gems',
            'to_currency'   => 'gold',
            'amount'        => 30,
        ])
        ->assertOk()
        ->assertJson([
            'deducted' => 30,
            'credited' => 234,
            'fee'      => 6,
        ]);
});

it('credits the exact example from the spec: 100 gold deducted, 9.75 credited, 0.25 fee', function () {
    $user = userWithWallets(gold: 100, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 100,
        ])
        ->assertOk()
        ->assertJson([
            'deducted' => 100,
            'credited' => 9.75,
            'fee'      => 0.25,
        ]);
});

it('writes an exchange log entry on success', function () {
    // 5 gold → gross 0.5 gems → net 0.4875 gems
    $user = userWithWallets(gold: 100, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 5,
        ])
        ->assertOk();

    $this->assertDatabaseHas('exchange_logs', [
        'user_id'       => $user->id,
        'from_currency' => 'gold',
        'to_currency'   => 'gems',
        'from_amount'   => 5,
        'to_amount'     => 0.4875,
    ]);
});

// ── balance safety ────────────────────────────────────────────────────────────

it('returns 422 when the user has insufficient balance', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 10,
        ])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Insufficient gold: need 10, have 5.']);
});

it('does not deduct balance when the exchange fails', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 10,
        ])
        ->assertUnprocessable();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 5]);
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 0]);
});

it('does not log an exchange when the balance check fails', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 10,
        ])
        ->assertUnprocessable();

    expect(ExchangeLog::where('user_id', $user->id)->count())->toBe(0);
});

// ── sequential exchange drains balance correctly ──────────────────────────────

it('two sequential exchanges accumulate the credited balance correctly', function () {
    // Exchange 1: 10 gold → 0.975 gems  |  Exchange 2: 10 gold → 0.975 gems
    // Total: gold = 0, gems = 1.95
    $user = userWithWallets(gold: 20, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 1.95]);
});

it('a third exchange on an empty wallet is rejected, not silently ignored', function () {
    $user = userWithWallets(gold: 20, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 1])
        ->assertUnprocessable();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
});
