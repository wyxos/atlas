<?php

namespace App\Services\SourceMedia;

use App\Models\File;

final class SourceUnwatchResult
{
    public function __construct(
        public readonly bool $supported,
        public readonly bool $unwatched,
        public readonly string $message,
        public readonly ?File $file = null,
    ) {}
}
