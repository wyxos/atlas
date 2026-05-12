<?php

namespace App\Services\MediaProcessing;

use App\Support\AtlasStorage;
use InvalidArgumentException;

class MediaProcessorPathValidator
{
    public function managedPath(?string $path): string
    {
        $path = trim(str_replace('\\', '/', (string) $path), '/');

        if ($path === '') {
            throw new InvalidArgumentException('Media processor path is required.');
        }

        $segments = explode('/', $path);
        if (in_array('..', $segments, true)) {
            throw new InvalidArgumentException('Media processor path traversal is not allowed.');
        }

        if (! in_array($segments[0] ?? '', AtlasStorage::namespaces(), true)) {
            throw new InvalidArgumentException('Media processor path must be inside Atlas managed storage.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $paths
     * @return array<string, string>
     */
    public function outputPaths(array $paths): array
    {
        $validated = [];
        foreach ($paths as $key => $path) {
            if (! is_string($key) || ! is_string($path) || $path === '') {
                continue;
            }

            $validated[$key] = $this->managedPath($path);
        }

        return $validated;
    }
}
