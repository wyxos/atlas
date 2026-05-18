<?php

namespace App\Services\Downloads;

final class ResolvedDownloadUrl
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $filename = null,
        public readonly ?int $filesize = null,
    ) {}
}
