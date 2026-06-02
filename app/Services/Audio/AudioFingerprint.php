<?php

namespace App\Services\Audio;

class AudioFingerprint
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly int $durationSeconds,
        public readonly string $path,
        public readonly string $engine = 'chromaprint',
    ) {}

    public function fingerprintSize(): int
    {
        return mb_strlen($this->fingerprint);
    }
}
