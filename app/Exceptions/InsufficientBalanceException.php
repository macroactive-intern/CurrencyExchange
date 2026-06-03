<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(string $currency, int $required, int $available)
    {
        parent::__construct(
            "Insufficient {$currency}: need {$required}, have {$available}."
        );
    }
}
