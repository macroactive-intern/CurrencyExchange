<?php

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
    $this->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertUnauthorized();
});

// ── validation ────────────────────────────────────────────────────────────────

it('rejects an unknown from-currency', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'silver', 'to' => 'gems', 'amount' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from');
});

it('rejects an unknown to-currency', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'dust', 'amount' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('to');
});

it('rejects same-currency exchanges', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gold', 'amount' => 10])
        ->assertUnprocessable();
});

it('rejects a zero amount', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

it('rejects a negative amount', function () {
    $user = userWithWallets();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => -5])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

// ── happy paths ───────────────────────────────────────────────────────────────

it('exchanges gold for gems at 1:10 rate', function () {
    $user = userWithWallets(gold: 50, gems: 0);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10]);

    $response->assertOk()
        ->assertJson([
            'from_currency' => 'gold',
            'to_currency'   => 'gems',
            'from_amount'   => 10,
            'to_amount'     => 100,
            'balances'      => ['gold' => 40, 'gems' => 100],
        ]);
});

it('exchanges gems for gold at 10:1 rate', function () {
    $user = userWithWallets(gold: 0, gems: 50);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gems', 'to' => 'gold', 'amount' => 30]);

    $response->assertOk()
        ->assertJson([
            'from_currency' => 'gems',
            'to_currency'   => 'gold',
            'from_amount'   => 30,
            'to_amount'     => 3,
            'balances'      => ['gems' => 20, 'gold' => 3],
        ]);
});

it('writes an exchange log entry on success', function () {
    $user = userWithWallets(gold: 100, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 5])
        ->assertOk();

    $this->assertDatabaseHas('exchange_logs', [
        'user_id'       => $user->id,
        'from_currency' => 'gold',
        'to_currency'   => 'gems',
        'from_amount'   => 5,
        'to_amount'     => 50,
    ]);
});

// ── balance safety ────────────────────────────────────────────────────────────

it('returns 422 when the user has insufficient balance', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Insufficient gold: need 10, have 5.']);
});

it('does not deduct balance when the exchange fails', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertUnprocessable();

    // Gold must be unchanged — no partial transfer
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 5]);
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 0]);
});

it('does not log an exchange when the balance check fails', function () {
    $user = userWithWallets(gold: 5, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertUnprocessable();

    expect(ExchangeLog::where('user_id', $user->id)->count())->toBe(0);
});

it('returns 422 when gems-to-gold amount is too small to yield 1 gold', function () {
    $user = userWithWallets(gold: 0, gems: 9);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gems', 'to' => 'gold', 'amount' => 9])
        ->assertUnprocessable();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 9]);
});

// ── sequential exchange drains balance correctly ──────────────────────────────

it('two sequential exchanges drain the balance correctly with no double-spend', function () {
    $user = userWithWallets(gold: 20, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gems', 'balance' => 200]);
});

it('a third exchange on an empty wallet is rejected, not silently ignored', function () {
    $user = userWithWallets(gold: 20, gems: 0);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 10])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/exchange', ['from' => 'gold', 'to' => 'gems', 'amount' => 1])
        ->assertUnprocessable();

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'gold', 'balance' => 0]);
});
