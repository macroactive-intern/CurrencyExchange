<?php

// Config under test:  gold_to_gems = 0.1,  gems_to_gold = 8.0,  fee_percent = 2.5
// Net multiplier   :  1 - 2.5/100 = 0.975
//
// gold → gems:  round(amount * 0.1 * 0.975, 2)  (e.g. 10 gold → 0.97 credited, 0.03 fee)
// gems → gold:  round(amount * 8.0 * 0.975, 2)  (e.g. 30 gems → 234 credited, 6 fee)

use App\Models\CurrencyBalance;
use App\Models\ExchangeTransaction;
use App\Models\User;

// ── helpers ──────────────────────────────────────────────────────────────────

function userWithWallets(int $gold = 100, int $gems = 0): User
{
    $user = User::factory()->create();
    CurrencyBalance::factory()->gold($gold)->for($user)->create();
    CurrencyBalance::factory()->gems($gems)->for($user)->create();

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
    // 10 gold → gross round(1.0,2)=1.00 → fee round(1.00*0.025,2)=0.03 → credited round(1.00-0.03,2)=0.97
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
            'credited' => 0.97,
            'fee'      => 0.03,
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
    // 5 gold → gross 0.5 gems → net round(0.4875,2)=0.49 gems
    $user = userWithWallets(gold: 100, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 5,
        ])
        ->assertOk();

    $this->assertDatabaseHas('exchange_transactions', [
        'user_id'       => $user->id,
        'from_currency' => 'gold',
        'to_currency'   => 'gems',
        'from_amount'   => 5,
        'to_amount'     => 0.49,
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
        ->assertJsonValidationErrors(['amount' => 'Insufficient balance.']);
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

    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 5]);
    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 0]);
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

    expect(ExchangeTransaction::where('user_id', $user->id)->count())->toBe(0);
});

// ── sequential exchange drains balance correctly ──────────────────────────────

it('two sequential exchanges accumulate the credited balance correctly', function () {
    // Exchange 1: 10 gold → 0.97 gems  |  Exchange 2: 10 gold → 0.97 gems
    // Total: gold = 0, gems = 1.94
    $user = userWithWallets(gold: 20, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from_currency' => 'gold', 'to_currency' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 1.94]);
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

    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
});
