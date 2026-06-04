<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExchangeService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TestExchangeCommand extends Command
{
    protected $signature = 'test:exchange {user_id} {from_currency} {to_currency} {amount}';

    protected $description = 'Test helper: run a single exchange for a user (concurrency testing only).';

    public function handle(ExchangeService $service): int
    {
        try {
            $user = User::findOrFail($this->argument('user_id'));
            $service->exchange(
                $user,
                $this->argument('from_currency'),
                $this->argument('to_currency'),
                (float) $this->argument('amount'),
            );

            return Command::SUCCESS;
        } catch (ValidationException|InvalidArgumentException $e) {
            return Command::FAILURE;
        }
    }
}
