<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests use RefreshDatabase — each test gets a clean MySQL state.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Concurrency tests manage their own database state (commits real rows so
// spawned artisan processes can see them). No RefreshDatabase.
pest()->extend(TestCase::class)
    ->in('Concurrency');
