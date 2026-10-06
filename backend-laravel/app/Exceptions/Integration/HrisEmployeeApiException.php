<?php

namespace App\Exceptions\Integration;

use RuntimeException;

class HrisEmployeeApiException extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}
