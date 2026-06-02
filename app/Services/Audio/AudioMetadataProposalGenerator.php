<?php

namespace App\Services\Audio;

use App\Models\Album;
use App\Models\Artist;
use App\Models\AudioMetadataProposal;
use App\Models\AudioMetadataRun;
use App\Models\File;
use App\Models\User;
use App\Services\Spotify\SpotifyOAuthConfig;
use App\Services\Spotify\SpotifyOAuthService;
use Illuminate\Support\Facades\Http;

class AudioMetadataProposalGenerator
{
    public function __construct(
        private readonly AudioCoverResolver $coverResolver,
        private readonly AudioMetadataFingerprintProvider $fingerprintProvider,
        private readonly AudioMetadataValueExtractor $values,
        private readonly SpotifyOAuthConfig $spotifyConfig,
        private readonly SpotifyOAuthService $spotifyOAuth,
    ) {}

    public function generate(AudioMetadataRun $run, File $file, User $user): ?AudioMetadataProposal
    {
        if (! str_starts_with((string) $file->mime_type, 'audio/')) {
            return null;
        }

        $file->loadMissing(['metadata', 'artists', 'albums.defaultCover']);

        $currentValues = $this->currentValues($file);
        $candidate = $this->isSpotifyFile($file)
            ? $this->spotifyCandidate($file, $user)
            : $this->localCandidate($file);

        $proposedValues = $candidate['values'];
        if ($proposedValues === []) {
            return null;
        }

        $changes = $this->changes($currentValues, $proposedValues);
        if ($changes === []) {
            return null;
        }

        AudioMetadataProposal::query()
            ->where('file_id', $file->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'superseded',
                'reviewed_at' => now(),
            ]);

        return AudioMetadataProposal::query()->create([
            'audio_metadata_run_id' => $run->id,
            'file_id' => $file->id,
            'provider' => $candidate['provider'],
            'status' => 'pending',
            'confidence' => $candidate['confidence'],
            'current_values' => $currentValues,
            'proposed_values' => $proposedValues,
            'changes' => $changes,
            'evidence' => $candidate['evidence'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(File $file): array
    {
        $artists = $file->artists
            ->map(fn (Artist $artist): string => trim((string) $artist->name))
            ->filter()
            ->values()
            ->all();
        $albums = $file->albums
            ->map(fn (Album $album): string => trim((string) $album->name))
            ->filter()
            ->values()
            ->all();

        return [
            'title' => $this->values->cleanString($file->title),
            'artists' => $artists,
            'album' => $albums[0] ?? null,
            'duration_seconds' => $this->values->durationSeconds($file, $this->values->metadataPayload($file)),
            'cover_url' => $this->coverResolver->forFile($file),
            'spotify_uri' => $this->spotifyUri($file),
        ];
    }

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    private function localCandidate(File $file): array
    {
        $fingerprintCandidate = $this->fingerprintProvider->candidate($file);
        $tagCandidate = $this->localTagCandidate($file);

        if ($fingerprintCandidate === null) {
            return $tagCandidate;
        }

        if ($tagCandidate['values'] === []) {
            return $fingerprintCandidate;
        }

        if ($fingerprintCandidate['confidence'] >= 65) {
            return $fingerprintCandidate;
        }

        return $fingerprintCandidate['confidence'] >= $tagCandidate['confidence']
            ? $fingerprintCandidate
            : $tagCandidate;
    }

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    private function localTagCandidate(File $file): array
    {
        $payload = $this->values->metadataPayload($file);
        $values = [];
        $evidence = ['source' => 'embedded_tags'];

        $title = $this->values->firstStringForKeys($payload, ['title']);
        $artists = $this->values->extractNames($payload, ['artist', 'artists', 'album_artist', 'albumArtist', 'albumartist', 'performer']);
        $album = $this->values->firstStringForKeys($payload, ['album', 'albums']);
        $duration = $this->values->durationSeconds($file, $payload);
        $coverUrl = $this->values->firstStringForKeys($payload, ['cover_url', 'artwork_url', 'thumbnail_url']);

        if ($title === null && $artists === []) {
            $filenameCandidate = $this->values->filenameCandidate((string) $file->filename);
            if ($filenameCandidate !== null) {
                $title = $filenameCandidate['title'];
                $artists = [$filenameCandidate['artist']];
                $evidence['source'] = 'filename';
            }
        }

        if ($title !== null) {
            $values['title'] = $title;
        }

        if ($artists !== []) {
            $values['artists'] = $artists;
        }

        if ($album !== null) {
            $values['album'] = $album;
        }

        if ($duration !== null) {
            $values['duration_seconds'] = $duration;
        }

        if ($coverUrl !== null) {
            $values['cover_url'] = $coverUrl;
        }

        return [
            'provider' => 'local',
            'confidence' => $this->localTagConfidence($values, (string) $evidence['source']),
            'values' => $values,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function localTagConfidence(array $values, string $source): int
    {
        if ($values === []) {
            return 0;
        }

        if ($source === 'filename') {
            return 45;
        }

        $confidence = 50;
        $confidence += array_key_exists('title', $values) ? 6 : 0;
        $confidence += array_key_exists('artists', $values) ? 6 : 0;
        $confidence += array_key_exists('album', $values) ? 4 : 0;
        $confidence += array_key_exists('duration_seconds', $values) ? 4 : 0;
        $confidence += array_key_exists('cover_url', $values) ? 3 : 0;

        return min(70, $confidence);
    }

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}
     */
    private function spotifyCandidate(File $file, User $user): array
    {
        $trackId = $this->spotifyTrackId((string) $file->source_id)
            ?? $this->spotifyTrackId((string) $file->url)
            ?? $this->spotifyTrackId((string) $file->referrer_url);
        $track = null;
        $evidence = ['source' => 'spotify', 'track_id' => $trackId, 'refetched' => false];

        if ($trackId !== null) {
            $accessToken = $this->spotifyOAuth->getValidAccessToken($user);
            if ($accessToken !== null) {
                $track = $this->fetchSpotifyTrack($trackId, $accessToken);
                $evidence['refetched'] = $track !== null;
            }
        }

        if ($track === null) {
            $listingTrack = data_get($file->listing_metadata, 'track');
            $track = is_array($listingTrack) ? $listingTrack : [];
            $evidence['source'] = 'spotify_listing_metadata';
        }

        $values = $this->spotifyValues($track);
        if (($values['spotify_uri'] ?? null) === null && $trackId !== null) {
            $values['spotify_uri'] = 'spotify:track:'.$trackId;
        }

        return [
            'provider' => 'spotify',
            'confidence' => $evidence['refetched'] ? 98 : 70,
            'values' => $values,
            'evidence' => $evidence,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSpotifyTrack(string $trackId, string $accessToken): ?array
    {
        $response = Http::acceptJson()
            ->withToken($accessToken)
            ->timeout(15)
            ->get(rtrim($this->spotifyConfig->apiBaseUrl(), '/').'/tracks/'.$trackId);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function spotifyValues(array $track): array
    {
        $values = [];
        $this->putIfPresent($values, 'title', $this->values->cleanString(data_get($track, 'name')));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList(data_get($track, 'artists.*.name', [])));
        $this->putIfPresent($values, 'album', $this->values->cleanString(data_get($track, 'album.name')));
        $this->putIfPresent($values, 'spotify_uri', $this->values->cleanString(data_get($track, 'uri')));
        $this->putIfPresent($values, 'isrc', $this->values->cleanString(data_get($track, 'external_ids.isrc')));
        $this->putIfPresent($values, 'cover_url', $this->bestSpotifyCoverUrl(data_get($track, 'album.images', [])));

        $duration = $this->values->positiveInteger(data_get($track, 'duration_ms'));
        if ($duration !== null) {
            $values['duration_seconds'] = (int) round($duration / 1000);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function putIfPresent(array &$values, string $key, mixed $value): void
    {
        if ($value === null || $value === []) {
            return;
        }

        $values[$key] = $value;
    }

    private function bestSpotifyCoverUrl(mixed $images): ?string
    {
        if (! is_array($images)) {
            return null;
        }

        $sortedImages = collect($images)
            ->filter(fn (mixed $image): bool => is_array($image) && $this->values->cleanString($image['url'] ?? null) !== null)
            ->sortByDesc(fn (array $image): int => (int) ($image['width'] ?? 0))
            ->values();

        return $this->values->cleanString($sortedImages->first()['url'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array<string, mixed>  $proposedValues
     * @return array<string, array{current:mixed,proposed:mixed}>
     */
    private function changes(array $currentValues, array $proposedValues): array
    {
        $changes = [];

        foreach (['title', 'artists', 'album', 'duration_seconds', 'cover_url', 'spotify_uri'] as $field) {
            if (! array_key_exists($field, $proposedValues)) {
                continue;
            }

            $current = $currentValues[$field] ?? null;
            $proposed = $proposedValues[$field];
            if (! $this->valuesMatch($current, $proposed)) {
                $changes[$field] = ['current' => $current, 'proposed' => $proposed];
            }
        }

        return $changes;
    }

    private function valuesMatch(mixed $current, mixed $proposed): bool
    {
        if (is_array($current) || is_array($proposed)) {
            return $this->normalizeComparableList($current) === $this->normalizeComparableList($proposed);
        }

        if (is_numeric($current) || is_numeric($proposed)) {
            return (string) $current === (string) $proposed;
        }

        return $this->normalizeComparableString($current) === $this->normalizeComparableString($proposed);
    }

    /**
     * @return list<string>
     */
    private function normalizeComparableList(mixed $value): array
    {
        return array_values(array_map(
            fn (string $entry): string => $this->normalizeComparableString($entry),
            $this->values->cleanStringList($value)
        ));
    }

    private function normalizeComparableString(mixed $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $value))) ?? '';
    }

    private function isSpotifyFile(File $file): bool
    {
        return mb_strtolower(trim((string) $file->source)) === 'spotify';
    }

    private function spotifyUri(File $file): ?string
    {
        $trackId = $this->spotifyTrackId((string) $file->source_id)
            ?? $this->spotifyTrackId((string) $file->url)
            ?? $this->spotifyTrackId((string) $file->referrer_url);

        return $trackId !== null ? 'spotify:track:'.$trackId : null;
    }

    private function spotifyTrackId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^spotify:track:([A-Za-z0-9]{22})$/', $value, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^[A-Za-z0-9]{22}$/', $value) === 1) {
            return $value;
        }

        if (preg_match('#open\.spotify\.com/track/([A-Za-z0-9]{22})#', $value, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
