<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(string $currency, int|float $required, int|float $available)
    {
        parent::__construct(
            "Insufficient {$currency}: need {$required}, have {$available}."
        );
    }
}
