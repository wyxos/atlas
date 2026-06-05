<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\File;
use App\Models\FileMetadata;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AudioMetadataFileRestorer
{
    private const RELEASE_FIELDS = [
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'musicbrainz_release_id',
        'discogs_release_id',
    ];

    private const TRACK_PIVOT_FIELDS = [
        'track_number',
        'disc_number',
    ];

    public function __construct(
        private readonly MediaProbeService $probe,
        private readonly AudioMetadataIngestionService $ingestion,
        private readonly AudioMetadataLocalTagProvider $localTags,
        private readonly AudioMetadataRelationshipSynchronizer $relationships,
        private readonly AudioMetadataCanonicalPayloadWriter $payloads,
    ) {}

    /**
     * @return array{values:array<string, mixed>,ingested:array{artists:list<string>,albums:list<string>,album_covers:int}}
     */
    public function restore(File $file): array
    {
        return DB::transaction(function () use ($file): array {
            $lockedFile = File::query()
                ->whereKey($file->id)
                ->lockForUpdate()
                ->firstOrFail();

            $resolved = AtlasPathResolver::resolveExistingPath($lockedFile->path, [AtlasStorage::DISK]);
            if (! $resolved) {
                throw new RuntimeException('Imported audio file is missing from Atlas app storage.');
            }

            $probe = $this->probe->probe($resolved['full_path']);
            if ($probe === []) {
                throw new RuntimeException('Embedded metadata could not be extracted from this audio file.');
            }

            $payload = $this->freshPayload($probe);
            $this->replaceMetadataPayload($lockedFile, $payload);

            $lockedFile->refresh()->load(['metadata', 'artists', 'albums']);
            $ingested = $this->ingestion->ingest($lockedFile, $payload, $resolved['full_path']);

            $lockedFile->refresh()->load(['metadata', 'artists', 'albums']);
            $values = $this->localTags->candidate($lockedFile, allowFilenameFallback: false)['values'];

            $this->restoreCatalogFields($lockedFile, $values);
            $this->payloads->apply($lockedFile->fresh('metadata') ?? $lockedFile, $values, array_keys($values));

            AudioMetadataProposal::query()
                ->where('file_id', $lockedFile->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'superseded',
                    'reviewed_at' => now(),
                ]);

            return [
                'values' => $values,
                'ingested' => $ingested,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    private function freshPayload(array $probe): array
    {
        return [
            'audio_restore' => [
                'source' => 'file',
                'restored_at' => now()->toIso8601String(),
            ],
            'probe' => $probe,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replaceMetadataPayload(File $file, array $payload): void
    {
        $metadata = FileMetadata::query()->firstOrNew(['file_id' => $file->id]);
        $metadata->payload = $payload;
        $metadata->is_review_required = false;
        $metadata->is_extracted = true;
        $metadata->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreCatalogFields(File $file, array $values): void
    {
        $this->restoreTitle($file, $values);
        $this->restoreArtists($file, $values);
        $this->restoreAlbum($file, $values);

        $file->refresh()->load('albums');
        $this->restoreAlbumMetadata($file, $values);
        $this->restoreTrackPivot($file, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreTitle(File $file, array $values): void
    {
        $file->forceFill([
            'title' => $this->cleanString($values['title'] ?? null),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreArtists(File $file, array $values): void
    {
        $artists = $this->cleanStringList($values['artists'] ?? []);
        if ($artists === []) {
            $file->artists()->detach();

            return;
        }

        $file->load('artists');
        $this->relationships->syncArtists($file, $artists);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreAlbum(File $file, array $values): void
    {
        $album = $this->cleanString($values['album'] ?? null);
        if ($album === null) {
            $file->albums()->detach();

            return;
        }

        $file->load('artists', 'albums');
        $this->relationships->syncAlbum($file, $album, $values);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreAlbumMetadata(File $file, array $values): void
    {
        $album = $file->albums->first();
        if (! $album instanceof Album) {
            return;
        }

        $updates = [];
        foreach (self::RELEASE_FIELDS as $field) {
            $updates[$field] = $this->cleanString($values[$field] ?? null);
        }

        $album->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function restoreTrackPivot(File $file, array $values): void
    {
        $album = $file->albums->first();
        if (! $album instanceof Album) {
            return;
        }

        $updates = [];
        foreach (self::TRACK_PIVOT_FIELDS as $field) {
            $updates[$field] = $this->cleanString($values[$field] ?? null);
        }

        $file->albums()->updateExistingPivot($album->id, $updates);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    /**
     * @return list<string>
     */
    private function cleanStringList(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $unique = [];
        foreach ($value as $entry) {
            $clean = $this->cleanString($entry);
            if ($clean !== null) {
                $unique[$this->normalizeName($clean)] = $clean;
            }
        }

        return array_values($unique);
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }
}
