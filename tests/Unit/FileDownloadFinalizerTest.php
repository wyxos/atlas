<?php

use App\Services\Downloads\FileDownloadFinalizer;
use Tests\TestCase;

uses(TestCase::class);

function fileDownloadFinalizerProbe(?int $availableMemory = null): FileDownloadFinalizer
{
    return new class($availableMemory) extends FileDownloadFinalizer
    {
        public function __construct(private readonly ?int $forcedAvailableMemory) {}

        public function parseLimit(string|false $memoryLimit): ?int
        {
            return $this->parseMemoryLimitToBytes($memoryLimit);
        }

        public function canGenerate(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): bool
        {
            return $this->canGenerateThumbnail($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight);
        }

        public function estimateUsage(int $originalWidth, int $originalHeight, int $thumbnailWidth, int $thumbnailHeight): int
        {
            return $this->estimateThumbnailMemoryUsage($originalWidth, $originalHeight, $thumbnailWidth, $thumbnailHeight);
        }

        protected function availableMemoryForThumbnailGeneration(): ?int
        {
            if ($this->forcedAvailableMemory !== null) {
                return $this->forcedAvailableMemory;
            }

            return parent::availableMemoryForThumbnailGeneration();
        }
    };
}

it('parses php memory limit shorthand values', function () {
    $probe = fileDownloadFinalizerProbe();

    expect($probe->parseLimit('128M'))->toBe(128 * 1024 * 1024)
        ->and($probe->parseLimit('2g'))->toBe(2 * 1024 * 1024 * 1024)
        ->and($probe->parseLimit('64K'))->toBe(64 * 1024)
        ->and($probe->parseLimit('-1'))->toBeNull();
});

it('skips thumbnails when the estimated GD memory exceeds what is available', function () {
    $probe = fileDownloadFinalizerProbe(128 * 1024 * 1024);

    expect($probe->canGenerate(8000, 8000, 450, 450))->toBeFalse();
});

it('allows thumbnails when the image memory estimate fits the worker budget', function () {
    $probe = fileDownloadFinalizerProbe(128 * 1024 * 1024);

    expect($probe->canGenerate(1200, 800, 450, 300))->toBeTrue()
        ->and($probe->estimateUsage(1200, 800, 450, 300))->toBeLessThan(128 * 1024 * 1024);
});
