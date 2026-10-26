<?php

namespace App\Exceptions;

use RuntimeException;

/** Raised when a domain rule (capacity, certification, closure) refuses a transaction. */
class BusinessRuleException extends RuntimeException
{
    public function __construct(public string $rule, string $message, public int $status = 409)
    {
        parent::__construct($message);
    }
}
