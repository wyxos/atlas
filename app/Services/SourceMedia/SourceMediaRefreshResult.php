<?php

namespace App\Services\SourceMedia;

use App\Models\File;

final class SourceMediaRefreshResult
{
    public function __construct(
        public readonly bool $supported,
        public readonly bool $changed,
        public readonly string $message,
        public readonly ?File $file = null,
    ) {}
}
