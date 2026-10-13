<?php

namespace App\Services\AI;

use RuntimeException;
use Throwable;

class AiClientException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $outcomeUnknown = true,
        public readonly bool $safeToRetry = false,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
