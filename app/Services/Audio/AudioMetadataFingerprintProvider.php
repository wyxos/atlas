<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class AudioMetadataFingerprintProvider
{
    public function __construct(
        private readonly AudioFingerprintService $fingerprints,
        private readonly MusicBrainzRecordingReleaseLookup $recordingReleases,
        private readonly MusicBrainzReleaseMetadata $releaseMetadata,
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @return array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}|null
     */
    public function candidate(File $file): ?array
    {
        $fingerprint = $this->fingerprints->forFile($file);
        if (! $fingerprint) {
            return null;
        }

        $clientKey = $this->clientKey();
        if ($clientKey === null) {
            return null;
        }

        $result = $this->bestAcoustIdResult($this->lookupAcoustId($fingerprint, $clientKey));
        if (! $result || $this->acoustIdScore($result) < $this->minimumAcoustIdScore()) {
            return null;
        }

        $recording = $this->bestRecording($result);
        if (! $recording) {
            return null;
        }

        $hints = $this->metadataHints($file);
        $recording = $this->recordingReleases->withReleases($recording);
        $release = $this->recordingReleases->bestRelease($recording, $hints);
        $releaseId = $this->cleanString($release['id'] ?? null);
        $releaseDetails = $releaseId !== null ? $this->releaseMetadata->fetch($releaseId) : [];
        if ($releaseDetails !== []) {
            $release = array_replace_recursive($release, $releaseDetails);
        }

        $proposedValues = $this->valuesFromRecording($recording, $release);
        if ($proposedValues === []) {
            return null;
        }

        $coverUrl = $this->coverUrlForRelease($this->cleanString($release['id'] ?? null));
        if ($coverUrl !== null) {
            $proposedValues['cover_url'] = $coverUrl;
        }

        $score = $this->scoreCandidate($file, $fingerprint, $result, $recording, $release, $coverUrl, $hints);

        return [
            'provider' => 'acoustid_musicbrainz',
            'confidence' => $score['confidence'],
            'values' => $proposedValues,
            'evidence' => $score['evidence'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lookupAcoustId(AudioFingerprint $fingerprint, string $clientKey): ?array
    {
        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->acoustIdBaseUrl(), '/').'/lookup', [
                    'client' => $clientKey,
                    'duration' => $fingerprint->durationSeconds,
                    'fingerprint' => $fingerprint->fingerprint,
                    'format' => 'json',
                    'meta' => 'recordings releases releasegroups tracks compress',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private function bestAcoustIdResult(?array $payload): ?array
    {
        if (($payload['status'] ?? null) !== 'ok' || ! is_array($payload['results'] ?? null)) {
            return null;
        }

        $result = collect($payload['results'])
            ->filter(fn (mixed $result): bool => is_array($result) && $this->bestRecording($result) !== null)
            ->sortByDesc(fn (array $result): float => $this->acoustIdScore($result))
            ->first();

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>|null
     */
    private function bestRecording(array $result): ?array
    {
        $recordings = $result['recordings'] ?? null;
        if (! is_array($recordings)) {
            return null;
        }

        $recording = collect($recordings)
            ->filter(fn (mixed $recording): bool => is_array($recording) && $this->cleanString($recording['title'] ?? null) !== null)
            ->sortByDesc(fn (array $recording): int => is_array($recording['releases'] ?? null) ? count($recording['releases']) : 0)
            ->first();

        return is_array($recording) ? $recording : null;
    }

    /**
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $release
     * @return array<string, mixed>
     */
    private function valuesFromRecording(array $recording, array $release): array
    {
        $values = [];
        $this->putIfPresent($values, 'title', $this->cleanString($recording['title'] ?? null));
        $this->putIfPresent($values, 'artists', $this->recordingArtists($recording));
        $this->putIfPresent($values, 'musicbrainz_recording_id', $this->cleanString($recording['id'] ?? null));

        foreach ($this->releaseMetadata->values($release, $this->cleanString($recording['id'] ?? null)) as $key => $value) {
            $this->putIfPresent($values, $key, $value);
        }

        $duration = $this->metadataDurationSeconds($recording['duration'] ?? $recording['length'] ?? null);
        if ($duration !== null) {
            $values['duration_seconds'] = $duration;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $recording
     * @return list<string>
     */
    private function recordingArtists(array $recording): array
    {
        $artists = $this->values->cleanStringList(data_get($recording, 'artists.*.name', []));
        if ($artists !== []) {
            return $artists;
        }

        $artistCredits = data_get($recording, 'artist-credit', []);
        if (! is_array($artistCredits)) {
            return [];
        }

        return $this->values->cleanStringList(collect($artistCredits)
            ->map(fn (mixed $credit): mixed => is_array($credit)
                ? ($credit['name'] ?? data_get($credit, 'artist.name'))
                : null)
            ->all());
    }

    private function coverUrlForRelease(?string $releaseId): ?string
    {
        if ($releaseId === null) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout((int) config('services.audio_metadata.http_timeout_seconds', 15))
                ->get(rtrim($this->coverArtArchiveBaseUrl(), '/').'/release/'.$releaseId);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $images = $response->json('images');
        if (! is_array($images)) {
            return null;
        }

        $image = collect($images)
            ->filter(fn (mixed $image): bool => is_array($image) && (bool) ($image['front'] ?? false))
            ->first() ?? collect($images)->first();

        if (! is_array($image)) {
            return null;
        }

        return $this->cleanString(data_get($image, 'thumbnails.large'))
            ?? $this->cleanString(data_get($image, 'thumbnails.500'))
            ?? $this->cleanString($image['image'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $release
     * @return array{confidence:int,evidence:array<string, mixed>}
     */
    private function scoreCandidate(
        File $file,
        AudioFingerprint $fingerprint,
        array $result,
        array $recording,
        array $release,
        ?string $coverUrl,
        array $hints,
    ): array {
        $acoustIdScore = $this->acoustIdScore($result);
        $confidence = (int) round($acoustIdScore * 100);
        $duration = $this->metadataDurationSeconds($recording['duration'] ?? $recording['length'] ?? null);
        $durationDelta = $duration !== null ? abs($fingerprint->durationSeconds - $duration) : null;
        $matchedFields = [];

        if ($durationDelta !== null) {
            if ($durationDelta <= 2) {
                $confidence += 5;
                $matchedFields[] = 'duration';
            } elseif ($durationDelta <= 5) {
                $confidence += 2;
            } elseif ($durationDelta > 10) {
                $confidence -= 8;
            }
        }

        if ($this->stringsMatch($hints['title'] ?? null, $recording['title'] ?? null)) {
            $confidence += 3;
            $matchedFields[] = 'title';
        }

        if ($this->artistsOverlap($hints['artists'] ?? [], $this->recordingArtists($recording))) {
            $confidence += 3;
            $matchedFields[] = 'artists';
        }

        if ($this->stringsMatch($hints['album'] ?? null, $release['title'] ?? null)) {
            $confidence += 2;
            $matchedFields[] = 'album';
        }

        if ($this->cleanString($release['id'] ?? null) !== null) {
            $confidence += 3;
        }

        if ($coverUrl !== null) {
            $confidence += 2;
        }

        $identitySupport = $this->identitySupport($matchedFields, $release, $coverUrl, $result, $durationDelta, $recording, $hints);
        if ($identitySupport === 'weak') {
            $confidence = min($confidence, 74);
        } elseif ($identitySupport === 'release_only') {
            $confidence = min($confidence, 84);
        } elseif ($identitySupport === 'strong_fingerprint_release') {
            $confidence = max($confidence, 90);
        }

        return [
            'confidence' => max(60, min(96, $confidence)),
            'evidence' => [
                'source' => 'acoustid_fingerprint',
                'fingerprint_engine' => $fingerprint->engine,
                'fingerprint_duration_seconds' => $fingerprint->durationSeconds,
                'fingerprint_size' => $fingerprint->fingerprintSize(),
                'acoustid_id' => $this->cleanString($result['id'] ?? null),
                'acoustid_score' => round($acoustIdScore * 100, 1),
                'musicbrainz_recording_id' => $this->cleanString($recording['id'] ?? null),
                'musicbrainz_release_id' => $this->cleanString($release['id'] ?? null),
                'duration_delta_seconds' => $durationDelta,
                'matched_existing_fields' => $matchedFields,
                'identity_support' => $identitySupport,
                'cover_source' => $coverUrl !== null ? 'cover_art_archive' : null,
            ],
        ];
    }

    /**
     * @return array{title:string|null,artists:list<string>,album:string|null,release_date:string|null,duration_seconds:int|null}
     */
    private function metadataHints(File $file): array
    {
        $payload = $this->values->metadataPayload($file);
        $artists = $this->values->extractNames($payload, ['artist', 'artists', 'album_artist', 'albumArtist', 'albumartist', 'performer']);
        if ($artists === []) {
            $artists = $file->artists
                ->map(fn ($artist): ?string => $this->cleanString($artist->name ?? null))
                ->filter()
                ->values()
                ->all();
        }

        return [
            'title' => $this->values->firstStringForKeys($payload, ['title']) ?? $this->cleanString($file->title),
            'artists' => $artists,
            'album' => $this->values->firstStringForKeys($payload, ['album', 'albums'])
                ?? $this->cleanString($file->albums->first()?->name ?? null),
            'release_date' => $this->values->firstStringForKeys($payload, ['date', 'year', 'originaldate', 'releasedate', 'release_date']),
            'duration_seconds' => $this->values->durationSeconds($file, $payload),
        ];
    }

    private function metadataDurationSeconds(mixed $value): ?int
    {
        $duration = $this->values->positiveInteger($value);
        if ($duration === null) {
            return null;
        }

        return $duration > 10000 ? (int) round($duration / 1000) : $duration;
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function acoustIdScore(array $result): float
    {
        return is_numeric($result['score'] ?? null) ? (float) $result['score'] : 0.0;
    }

    /**
     * @param  list<string>  $matchedFields
     * @param  array<string, mixed>  $release
     */
    private function identitySupport(
        array $matchedFields,
        array $release,
        ?string $coverUrl,
        array $result,
        ?int $durationDelta,
        array $recording,
        array $hints,
    ): string {
        if (array_values(array_intersect($matchedFields, ['title', 'artists', 'album'])) !== []) {
            return 'matched_existing_identity';
        }

        if ($this->hasStrongFingerprintReleaseSupport($release, $result, $durationDelta, $recording, $hints)) {
            return 'strong_fingerprint_release';
        }

        if ($this->cleanString($release['id'] ?? null) !== null && $coverUrl !== null) {
            return 'release_with_cover';
        }

        if ($this->cleanString($release['id'] ?? null) !== null) {
            return 'release_only';
        }

        return 'weak';
    }

    /**
     * @param  array<string, mixed>  $release
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $recording
     * @param  array<string, mixed>  $hints
     */
    private function hasStrongFingerprintReleaseSupport(
        array $release,
        array $result,
        ?int $durationDelta,
        array $recording,
        array $hints,
    ): bool {
        if ($this->acoustIdScore($result) < 0.99 || $durationDelta === null || $durationDelta > 2) {
            return false;
        }

        if ($this->cleanString($release['id'] ?? null) === null) {
            return false;
        }

        $releaseTitle = $this->cleanString($release['title'] ?? null);
        $recordingTitle = $this->cleanString($recording['title'] ?? null);

        return $this->stringsMatch($releaseTitle, $recordingTitle)
            || $this->stringsMatch($releaseTitle, $hints['album'] ?? null)
            || $this->stringsMatch($releaseTitle, $hints['title'] ?? null);
    }

    private function stringsMatch(mixed $left, mixed $right): bool
    {
        $left = $this->cleanString($left);
        $right = $this->cleanString($right);

        return $left !== null
            && $right !== null
            && $this->normalizeComparableString($left) === $this->normalizeComparableString($right);
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function artistsOverlap(array $left, array $right): bool
    {
        $left = array_map(fn (string $name): string => $this->normalizeComparableString($name), $left);
        $right = array_map(fn (string $name): string => $this->normalizeComparableString($name), $right);

        return array_values(array_intersect($left, $right)) !== [];
    }

    private function normalizeComparableString(string $value): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim($value))) ?? '';
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function clientKey(): ?string
    {
        return $this->cleanString(config('services.audio_metadata.acoustid_client_key'));
    }

    private function minimumAcoustIdScore(): float
    {
        $score = config('services.audio_metadata.acoustid_min_score', 0.65);

        return is_numeric($score) ? (float) $score : 0.65;
    }

    private function acoustIdBaseUrl(): string
    {
        return (string) config('services.audio_metadata.acoustid_api_base_url', 'https://api.acoustid.org/v2');
    }

    private function coverArtArchiveBaseUrl(): string
    {
        return (string) config('services.audio_metadata.cover_art_archive_base_url', 'https://coverartarchive.org');
    }

    private function userAgent(): string
    {
        return (string) config('services.audio_metadata.user_agent', 'Atlas/1.0');
    }
}
