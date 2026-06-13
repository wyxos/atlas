<?php

namespace App\Services;

use App\Browser;
use App\Enums\SourceMetadataRestoreTarget;
use App\Models\File;
use ReflectionClass;

class SourceMetadataRestoreService
{
    public function __construct(
        private readonly CivitAiMetadataRestoreService $civitAiMetadataRestoreService,
    ) {}

    public function supports(File $file, string $target): bool
    {
        if (! SourceMetadataRestoreTarget::isValid($target)) {
            return false;
        }

        $service = $this->resolveServiceForFile($file);
        if (! $service instanceof RestoresSourceMetadata) {
            return false;
        }

        return match ($target) {
            SourceMetadataRestoreTarget::LISTING => $service->supportsListingMetadataRestore($file),
            SourceMetadataRestoreTarget::DETAIL => $service->supportsDetailMetadataRestore($file),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(File $file, string $target): array
    {
        if (! SourceMetadataRestoreTarget::isValid($target)) {
            return $this->result($file, 'unsupported_target', $target);
        }

        if (! $this->supports($file, $target)) {
            return $this->unsupportedResult($file, $target);
        }

        $service = $this->resolveServiceForFile($file);

        return match (true) {
            $service instanceof CivitAiImages => $this->civitAiMetadataRestoreService->restore($file, $target),
            default => $this->result($file, 'unsupported_provider', $target),
        };
    }

    private function resolveServiceForFile(File $file): ?BaseService
    {
        $source = $this->normalizeString($file->source);
        if ($source === null) {
            return null;
        }

        $browser = new Browser;
        $reflection = new ReflectionClass($browser);
        $method = $reflection->getMethod('getAvailableServices');
        $method->setAccessible(true);
        $services = $method->invoke($browser);

        foreach ($services as $key => $serviceClass) {
            /** @var BaseService $service */
            $service = app($serviceClass);
            if (strtolower($service::source()) === strtolower($source)) {
                return $service;
            }

            if (strtolower((string) $key) === strtolower($source)) {
                return $service;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function unsupportedResult(File $file, string $target): array
    {
        $source = $this->normalizeString($file->source);
        if ($source === null || strtolower($source) === 'local') {
            return $this->result($file, 'unsupported_source', $target);
        }

        $service = $this->resolveServiceForFile($file);
        $sourceId = $this->normalizeString($file->source_id);
        if ($sourceId === null || ($service instanceof CivitAiImages && preg_match('/^[0-9]+$/', $sourceId) !== 1)) {
            return $this->result($file, 'invalid_source_id', $target);
        }

        return $this->result($file, 'unsupported_provider', $target);
    }

    /**
     * @return array<string, mixed>
     */
    private function result(File $file, string $status, string $target): array
    {
        return [
            'file_id' => (int) $file->id,
            'source_id' => $this->normalizeString($file->source_id),
            'status' => $status,
            'target' => $target,
        ];
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
