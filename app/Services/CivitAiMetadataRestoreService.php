<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CivitAiMetadataRestoreService
{
    private const array POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    public function __construct(
        private readonly CivitAiImages $civitAiImages,
        private readonly BrowsePersister $browsePersister,
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

    public function restore(File $file): array
    {
        $sourceId = $this->normalizeSourceId($file->source_id);
        if ($sourceId === null) {
            return $this->result($file, 'invalid_source_id');
        }

        try {
            $row = $this->fetchImageRow($sourceId);
        } catch (\Throwable) {
            return $this->result($file, 'api_error');
        }

        if ($row === null) {
            return $this->result($file, 'not_found');
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
            return $this->result($file, 'invalid_response');
        }

        return $this->applyTransformedItem($file, $sourceId, $item);
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

    private function applyTransformedItem(File $file, string $sourceId, array $item): array
    {
        $fileRow = is_array($item['file'] ?? null) ? $item['file'] : [];
        $metadataRow = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
        $listingMetadata = $this->decodeListingMetadata($fileRow['listing_metadata'] ?? null);

        $containersBefore = $file->containers()->count();

        $file->forceFill(array_filter([
            'source' => CivitAiImages::SOURCE,
            'source_id' => $sourceId,
            'url' => $this->filledString($fileRow['url'] ?? null),
            'referrer_url' => $this->filledString($fileRow['referrer_url'] ?? null),
            'preview_url' => $this->filledString($fileRow['preview_url'] ?? null),
            'ext' => $this->filledString($fileRow['ext'] ?? null),
            'mime_type' => $this->filledString($fileRow['mime_type'] ?? null),
            'hash' => $this->filledString($fileRow['hash'] ?? null),
            'listing_metadata' => $listingMetadata,
        ], static fn (mixed $value): bool => $value !== null))->save();

        $this->restoreMetadata($file, $metadataRow);

        $fileForContainers = File::query()
            ->select(['id', 'source', 'source_id', 'listing_metadata', 'detail_metadata', 'downloaded', 'blacklisted_at'])
            ->find($file->id);

        if ($fileForContainers) {
            $this->browsePersister->attachContainersForFiles(new Collection([$fileForContainers]));
        }

        $containerIds = $file->containers()
            ->orderBy('containers.id')
            ->pluck('containers.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            ...$this->result($file, 'restored'),
            'containers_before' => $containersBefore,
            'containers_after' => count($containerIds),
            'container_ids' => $containerIds,
        ];
    }

    private function restoreMetadata(File $file, array $metadataRow): void
    {
        $payload = $metadataRow['payload'] ?? null;
        if (is_array($payload)) {
            $payload = json_encode($payload);
        }

        if (! is_string($payload) || trim($payload) === '') {
            return;
        }

        FileMetadata::query()->updateOrCreate(
            ['file_id' => $file->id],
            ['payload' => $payload],
        );
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

    private function result(File $file, string $status): array
    {
        return [
            'file_id' => (int) $file->id,
            'source_id' => is_scalar($file->source_id) ? (string) $file->source_id : null,
            'status' => $status,
        ];
    }
}
