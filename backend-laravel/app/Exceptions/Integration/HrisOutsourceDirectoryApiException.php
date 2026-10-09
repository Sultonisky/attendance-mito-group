<?php

namespace App\Exceptions\Integration;

use RuntimeException;

class HrisOutsourceDirectoryApiException extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
