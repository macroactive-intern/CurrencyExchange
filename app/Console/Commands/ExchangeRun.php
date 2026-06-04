<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ExchangeService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ExchangeRun extends Command
{
    protected $signature = 'exchange:run {userId} {from} {to} {amount}';

    protected $description = 'Run a single exchange for a user (used by the concurrency test).';

    public function handle(ExchangeService $service): int
    {
        try {
            $user = User::findOrFail($this->argument('userId'));
            $service->exchange(
                $user,
                $this->argument('from'),
                $this->argument('to'),
                (float) $this->argument('amount')
            );

            $this->line('ok');

            return Command::SUCCESS;
        } catch (ValidationException|InvalidArgumentException $e) {
            $this->line('fail: '.$e->getMessage());

            return Command::FAILURE;
        }
    }
}
