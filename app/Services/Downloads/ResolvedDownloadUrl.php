<?php

namespace App\Services\Downloads;

use Carbon\CarbonImmutable;

final class ResolvedDownloadUrl
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $filename = null,
        public readonly ?int $filesize = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly bool $providerResolved = false,
    ) {}
}
