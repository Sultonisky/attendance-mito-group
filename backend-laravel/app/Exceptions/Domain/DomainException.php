<?php

namespace App\Exceptions\Domain;

use Exception;

/**
 * Base exception for all domain/business rule violations.
 *
 * A DomainException represents a business operation that cannot legally
 * proceed. It is distinct from programming errors, framework errors,
 * validation syntax errors, and infrastructure failures.
 */
class DomainException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
