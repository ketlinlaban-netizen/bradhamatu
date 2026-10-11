<?php

namespace App\Services;

use RuntimeException;

class RouterApiException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly string $safeMessage,
        ?\Throwable $previous = null,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
