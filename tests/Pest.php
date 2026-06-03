<?php

// Feature tests use RefreshDatabase — each test gets a clean MySQL state.
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests manage their own database state (commits real rows so
// spawned artisan processes can see them). No RefreshDatabase.
pest()->extend(Tests\TestCase::class)
    ->in('Concurrency');
