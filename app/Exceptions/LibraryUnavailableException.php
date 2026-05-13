<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class LibraryUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Library unavailable',
        private readonly string $service = 'local',
        private readonly string $reason = 'typesense_unavailable',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function service(): string
    {
        return $this->service;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
