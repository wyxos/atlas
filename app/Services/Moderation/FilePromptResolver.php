<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Models\File;

final class FilePromptResolver
{
    public function resolve(File $file): ?string
    {
        $payload = (array) optional($file->metadata)->payload;

        $prompt = data_get($payload, 'prompt')
            ?? data_get($payload, 'meta.prompt')
            ?? data_get($file->detail_metadata, 'prompt')
            ?? data_get($file->listing_metadata, 'meta.prompt')
            ?? data_get($file->listing_metadata, 'meta.meta.prompt');

        if (! is_string($prompt) || $prompt === '') {
            return null;
        }

        return $prompt;
    }
}
