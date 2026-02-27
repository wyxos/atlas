<?php

namespace App\Services\Spotify;

use RuntimeException;

class SpotifyOAuthException extends RuntimeException
{
    public function __construct(string $message, public bool $requiresReconnect = false)
    {
        parent::__construct($message);
    }
}
