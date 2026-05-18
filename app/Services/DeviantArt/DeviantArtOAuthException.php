<?php

namespace App\Services\DeviantArt;

use RuntimeException;

class DeviantArtOAuthException extends RuntimeException
{
    public function __construct(string $message, public bool $requiresReconnect = false)
    {
        parent::__construct($message);
    }
}
