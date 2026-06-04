<?php

// Config under test:  gold_to_gems = 0.1,  fee_percent = 2.5
//
// 100 gold → gross = round(100 * 0.1, 2) = 10.00
//            fee   = round(10.00 * 0.025, 2) = 0.25
//            net   = round(10.00 - 0.25, 2)  = 9.75

use App\Models\CurrencyBalance;
use App\Models\ExchangeTransaction;
use App\Models\User;

function makeUser(float $gold = 0.00, float $gems = 0.00): User
{
    $user = User::factory()->create();
    CurrencyBalance::factory()->gold($gold)->for($user)->create();
    CurrencyBalance::factory()->gems($gems)->for($user)->create();

    return $user;
}

// ── Test 1 — exchange deducts and credits correctly ───────────────────────────

it('exchange deducts and credits correctly', function () {
    $user = makeUser(gold: 1000.00, gems: 0.00);

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

    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 900.00]);
    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 9.75]);
    expect(ExchangeTransaction::where('user_id', $user->id)->count())->toBe(1);
});

// ── Test 2 — fee is applied correctly ────────────────────────────────────────

it('fee is applied correctly', function () {
    // 100 gold at rate 0.1 → gross_credit = 10.00
    // fee (2.5% of gross) = 0.25
    // final credit        = 9.75
    // gross = credited + fee must equal 10.00
    $user = makeUser(gold: 1000.00, gems: 0.00);

    $data = $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 100,
        ])
        ->assertOk()
        ->json();

    expect($data['fee'])->toBe(0.25);
    expect($data['credited'])->toBe(9.75);
    expect(round($data['credited'] + $data['fee'], 2))->toBe(10.00);
});

// ── Test 3 — insufficient balance returns 422 ─────────────────────────────────

it('insufficient balance returns 422', function () {
    $user = makeUser(gold: 50.00, gems: 0.00);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'amount'        => 100,
        ])
        ->assertUnprocessable();

    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 50.00]);
    $this->assertDatabaseHas('currency_balances', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 0.00]);
    expect(ExchangeTransaction::where('user_id', $user->id)->count())->toBe(0);
});

// ── Test 4 — invalid exchange pair returns 422 ────────────────────────────────

it('invalid exchange pair returns 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'wood',
            'to_currency'   => 'stone',
            'amount'        => 100,
        ])
        ->assertUnprocessable();
});

// ── Test 5 — same currency is rejected ───────────────────────────────────────

it('same currency is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', [
            'from_currency' => 'gold',
            'to_currency'   => 'gold',
            'amount'        => 100,
        ])
        ->assertUnprocessable();
});
