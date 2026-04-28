<?php

namespace App\Services\Downloads;

class FileThumbnailMemoryGuard
{
    public function canGenerate(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): bool
    {
        $availableMemory = $this->availableMemoryForThumbnailGeneration();
        if ($availableMemory === null) {
            return true;
        }

        if ($availableMemory <= 0) {
            return false;
        }

        return $this->estimateUsage($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight) <= (int) floor($availableMemory * 0.5);
    }

    public function estimateUsage(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): int
    {
        // GD keeps a decoded bitmap of the source image and the resized destination in memory.
        // Use a deliberately pessimistic estimate so large images are skipped instead of crashing a 128 MB worker.
        return (int) ceil(($originalWidth * $originalHeight * 8) + ($thumbnailWidth * $thumbnailHeight * 8) + (16 * 1024 * 1024));
    }

    public function parseLimitToBytes(string|false $memoryLimit): ?int
    {
        if ($memoryLimit === false) {
            return null;
        }

        $memoryLimit = trim(strtolower($memoryLimit));
        if ($memoryLimit === '' || $memoryLimit === '-1') {
            return null;
        }

        if (! preg_match('/^(?<value>\d+)(?<unit>[kmg])?$/', $memoryLimit, $matches)) {
            return null;
        }

        $value = (int) $matches['value'];
        $unit = $matches['unit'] ?? '';

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    protected function availableMemoryForThumbnailGeneration(): ?int
    {
        $memoryLimit = $this->parseLimitToBytes(ini_get('memory_limit'));
        if ($memoryLimit === null) {
            return null;
        }

        return $memoryLimit - memory_get_usage(true);
    }
}
