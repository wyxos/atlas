<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\AudioMetadataProposal;
use App\Models\File;
use Illuminate\Support\Facades\DB;

class AudioMetadataAlbumGroupApplier
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

    public function __construct(
        private readonly AudioMetadataCanonicalPayloadWriter $payloads,
        private readonly AudioAlbumFolderMatcher $albumFolders,
    ) {}

    /**
     * @param  list<string>  $fields
     * @return array{peer_ids:list<int>,source_album_ids:list<int>}
     */
    public function capture(File $file, AudioMetadataProposal $proposal, array $fields): array
    {
        if (! in_array('album', $fields, true)) {
            return ['peer_ids' => [], 'source_album_ids' => []];
        }

        $currentAlbumName = $this->cleanString(data_get($proposal->current_values, 'album'))
            ?? $this->cleanString(data_get($proposal->changes, 'album.current'));
        if ($currentAlbumName === null) {
            return ['peer_ids' => [], 'source_album_ids' => []];
        }

        $sourceAlbumIds = Album::query()
            ->where('normalized_name', $this->normalizeName($currentAlbumName))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($sourceAlbumIds === []) {
            return ['peer_ids' => [], 'source_album_ids' => []];
        }

        $peerQuery = File::query()
            ->select('files.id')
            ->join('album_file', 'files.id', '=', 'album_file.file_id')
            ->whereIn('album_file.album_id', $sourceAlbumIds)
            ->whereKeyNot($file->id)
            ->distinct();

        $directory = $this->albumFolders->directory((string) $file->path);
        if ($directory !== null && $this->albumFolders->looksLikeAlbum($directory, $currentAlbumName)) {
            $peerQuery
                ->where('files.source', $file->source)
                ->where('files.path', 'like', $this->albumFolders->likePathPrefix($directory));
        } elseif ($this->canCaptureDuplicateSourceAlbums($file, $proposal, $currentAlbumName)) {
            $peerQuery->where('files.source', $file->source);
        } else {
            $currentAlbumIds = $file->albums
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->intersect($sourceAlbumIds)
                ->values()
                ->all();

            if ($currentAlbumIds === []) {
                return ['peer_ids' => [], 'source_album_ids' => $sourceAlbumIds];
            }

            $peerQuery->whereIn('album_file.album_id', $currentAlbumIds);
        }

        return [
            'peer_ids' => $peerQuery
                ->pluck('files.id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'source_album_ids' => $sourceAlbumIds,
        ];
    }

    /**
     * @param  array{peer_ids:list<int>,source_album_ids:list<int>}  $context
     * @param  array<string, mixed>  $proposed
     * @param  list<string>  $fields
     */
    public function apply(File $file, array $context, array $proposed, array $fields): void
    {
        if ($context['peer_ids'] === []) {
            return;
        }

        $targetAlbum = $file->fresh(['albums'])?->albums->first();
        if (! $targetAlbum instanceof Album) {
            return;
        }

        $payloadFields = $this->groupPayloadFields($fields);
        $payload = [
            ...$proposed,
            'album' => $targetAlbum->name,
        ];

        File::query()
            ->with(['albums', 'metadata'])
            ->whereKey($context['peer_ids'])
            ->lockForUpdate()
            ->get()
            ->each(function (File $peer) use ($context, $payload, $payloadFields, $targetAlbum): void {
                $sourceAlbum = $peer->albums
                    ->first(fn (Album $album): bool => in_array((int) $album->id, $context['source_album_ids'], true));

                $peer->albums()->sync([
                    $targetAlbum->id => array_filter([
                        'track_number' => $sourceAlbum?->pivot?->track_number,
                        'disc_number' => $sourceAlbum?->pivot?->disc_number,
                    ], fn (mixed $value): bool => $value !== null),
                ]);

                if ($payloadFields !== []) {
                    $this->payloads->apply($peer, $payload, $payloadFields);
                }
            });

        $this->deleteEmptySourceAlbums($context['source_album_ids'], (int) $targetAlbum->id);
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function groupPayloadFields(array $fields): array
    {
        return array_values(array_intersect($fields, ['album', ...self::RELEASE_FIELDS]));
    }

    /**
     * @param  list<int>  $sourceAlbumIds
     */
    private function deleteEmptySourceAlbums(array $sourceAlbumIds, int $targetAlbumId): void
    {
        $deleteIds = Album::query()
            ->whereIn('id', array_values(array_diff($sourceAlbumIds, [$targetAlbumId])))
            ->doesntHave('files')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($deleteIds === []) {
            return;
        }

        DB::table('metadata_aliases')
            ->where('aliasable_type', Album::class)
            ->whereIn('aliasable_id', $deleteIds)
            ->delete();

        Album::query()
            ->whereIn('id', $deleteIds)
            ->delete();
    }

    private function canCaptureDuplicateSourceAlbums(File $file, AudioMetadataProposal $proposal, string $currentAlbumName): bool
    {
        return str_starts_with((string) $file->mime_type, 'audio/')
            && mb_strtolower((string) $file->source) === 'local'
            && $this->hasReleaseEvidence($proposal)
            && $this->hasDistinctiveAlbumName($currentAlbumName);
    }

    private function hasReleaseEvidence(AudioMetadataProposal $proposal): bool
    {
        return $this->cleanString(data_get($proposal->proposed_values, 'discogs_release_id')) !== null
            || $this->cleanString(data_get($proposal->proposed_values, 'musicbrainz_release_id')) !== null
            || $this->cleanString(data_get($proposal->evidence, 'discogs_release_id')) !== null
            || $this->cleanString(data_get($proposal->evidence, 'musicbrainz_release_id')) !== null;
    }

    private function hasDistinctiveAlbumName(string $name): bool
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token): bool => $token !== ''));

        return count($tokens) >= 3
            && (str_contains($name, ' - ')
                || preg_match('/\b(?:cd|disc|vol|volume|ost|soundtrack|remix|remixes|ep)\s*\d*\b/i', $name) === 1);
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower(trim($name))) ?? '');
    }
}
