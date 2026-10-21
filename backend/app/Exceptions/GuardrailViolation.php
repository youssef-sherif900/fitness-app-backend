<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a calorie target or weekly rate breaks a server-side safety rule (PROF-3). */
class GuardrailViolation extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
