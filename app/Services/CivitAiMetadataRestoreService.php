<?php

namespace App\Services;

use App\Enums\SourceMetadataRestoreTarget;
use App\Models\File;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CivitAiMetadataRestoreService
{
    private const array POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    public function __construct(
        private readonly CivitAiImages $civitAiImages,
        private readonly BrowsePersister $browsePersister,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {}

    public function missingContainerQuery(?int $fileId = null, int $startId = 0): Builder
    {
        return File::query()
            ->select(['id', 'source_id'])
            ->where('id', '>', max(0, $startId))
            ->when($fileId !== null, fn (Builder $query) => $query->where('id', $fileId))
            ->where('source', CivitAiImages::SOURCE)
            ->whereNotNull('source_id')
            ->whereRaw("TRIM(source_id) <> ''")
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('reactions')
                    ->whereColumn('reactions.file_id', 'files.id')
                    ->whereIn('reactions.type', self::POSITIVE_REACTION_TYPES);
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('container_file')
                    ->whereColumn('container_file.file_id', 'files.id');
            })
            ->orderBy('id');
    }

    public function restore(File $file, string $target = SourceMetadataRestoreTarget::LISTING): array
    {
        if (! SourceMetadataRestoreTarget::isValid($target)) {
            return $this->result($file, 'unsupported_target', $target);
        }

        if ($this->normalizeSource($file->source) !== CivitAiImages::SOURCE) {
            return $this->result($file, 'unsupported_source', $target);
        }

        $sourceId = $this->normalizeSourceId($file->source_id);
        if ($sourceId === null) {
            return $this->result($file, 'invalid_source_id', $target);
        }

        try {
            $row = $this->fetchImageRow($sourceId);
        } catch (\Throwable) {
            return $this->result($file, 'api_error', $target);
        }

        if ($row === null) {
            return $this->result($file, 'not_found', $target);
        }

        $transformed = $this->civitAiImages->transform([
            'items' => [$row],
            'metadata' => ['nextCursor' => null],
        ], [
            'imageId' => $sourceId,
            'limit' => 1,
        ]);

        $item = $transformed['files'][0] ?? null;
        if (! is_array($item)) {
            return $this->result($file, 'invalid_response', $target);
        }

        return $this->applyTransformedItem($file, $item, $target);
    }

    private function fetchImageRow(string $sourceId): ?array
    {
        $response = $this->civitAiImages->fetch([
            'imageId' => $sourceId,
            'limit' => 1,
        ]);

        $rows = $response['items'] ?? null;
        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row) || (string) ($row['id'] ?? '') !== $sourceId) {
                continue;
            }

            return $row;
        }

        return null;
    }

    private function applyTransformedItem(File $file, array $item, string $target): array
    {
        $fileRow = is_array($item['file'] ?? null) ? $item['file'] : [];
        $incomingMetadata = $this->decodeListingMetadata($fileRow['listing_metadata'] ?? null);
        $targetColumn = SourceMetadataRestoreTarget::column($target);
        $existingMetadata = is_array($file->{$targetColumn}) ? $file->{$targetColumn} : [];
        $mergedMetadata = $incomingMetadata !== null
            ? $this->mergeMetadata($existingMetadata, $incomingMetadata)
            : null;

        $containersBefore = $file->containers()->count();

        $file->forceFill(array_filter([
            $targetColumn => $mergedMetadata,
        ], static fn (mixed $value): bool => $value !== null))->save();

        if ($target === SourceMetadataRestoreTarget::LISTING) {
            $fileForContainers = File::query()
                ->select(['id', 'source', 'source_id', 'listing_metadata', 'detail_metadata', 'downloaded', 'blacklisted_at'])
                ->find($file->id);

            if ($fileForContainers) {
                $this->browsePersister->attachContainersForFiles(new Collection([$fileForContainers]));
            }
        }

        $this->libraryIndexSyncDispatcher->files([(int) $file->id]);

        $containerIds = $file->containers()
            ->orderBy('containers.id')
            ->pluck('containers.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            ...$this->result($file, 'restored', $target),
            'containers_before' => $containersBefore,
            'containers_after' => count($containerIds),
            'container_ids' => $containerIds,
        ];
    }

    private function mergeMetadata(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (
                is_array($value)
                && isset($existing[$key])
                && is_array($existing[$key])
                && ! array_is_list($value)
                && ! array_is_list($existing[$key])
            ) {
                $existing[$key] = $this->mergeMetadata($existing[$key], $value);

                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    private function normalizeSource(mixed $value): ?string
    {
        $source = $this->filledString(is_scalar($value) ? (string) $value : null);
        if ($source === null) {
            return null;
        }

        return strtolower($source) === strtolower(CivitAiImages::SOURCE)
            ? CivitAiImages::SOURCE
            : $source;
    }

    private function decodeListingMetadata(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeSourceId(mixed $value): ?string
    {
        $sourceId = $this->filledString(is_scalar($value) ? (string) $value : null);

        return $sourceId !== null && preg_match('/^[0-9]+$/', $sourceId) === 1 ? $sourceId : null;
    }

    private function result(File $file, string $status, string $target): array
    {
        return [
            'file_id' => (int) $file->id,
            'source_id' => is_scalar($file->source_id) ? (string) $file->source_id : null,
            'status' => $status,
            'target' => $target,
        ];
    }
}
