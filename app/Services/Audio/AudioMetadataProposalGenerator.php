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
    private const REVIEW_FIELDS = [
        'title', 'artists', 'album', 'duration_seconds', 'cover_url', 'spotify_uri',
        'track_number', 'disc_number', 'release_label', 'catalog_number', 'barcode',
        'release_date', 'release_country', 'isrc', 'musicbrainz_recording_id', 'musicbrainz_release_id', 'discogs_release_id',
    ];

    public function __construct(
        private readonly AudioCoverResolver $coverResolver,
        private readonly AudioMetadataAiReviewer $aiReviewer,
        private readonly AudioMetadataCandidateEnricher $candidateEnricher,
        private readonly AudioMetadataCandidateFieldReviewer $fieldReviewer,
        private readonly AudioMetadataCoverLookupProvider $coverLookup,
        private readonly AudioMetadataDiscogsProvider $discogsProvider,
        private readonly AudioMetadataFingerprintProvider $fingerprintProvider,
        private readonly AudioMetadataLocalTagProvider $localTags,
        private readonly AudioMetadataVgmdbCandidateMerger $vgmdbCandidates,
        private readonly AudioMetadataVgmdbProvider $vgmdbProvider,
        private readonly AudioMetadataValueExtractor $values,
        private readonly SpotifyOAuthConfig $spotifyConfig,
        private readonly SpotifyOAuthService $spotifyOAuth,
    ) {}

    public function generate(AudioMetadataRun $run, File $file, User $user, ?callable $progress = null): ?AudioMetadataProposal
    {
        if (! str_starts_with((string) $file->mime_type, 'audio/')) {
            return null;
        }

        $file->loadMissing(['metadata', 'artists', 'albums.defaultCover']);

        $this->reportProgress($progress, 'metadata', 'Reading current metadata');
        $currentValues = $this->currentValues($file);
        $candidate = $this->isSpotifyFile($file)
            ? $this->spotifyCandidate($file, $user, $progress)
            : $this->localCandidate($file, $currentValues, $progress);

        if ($candidate === null) {
            return null;
        }

        $candidate['values'] = $this->withoutAliasValues($candidate['values']);
        if ($candidate['values'] === []) {
            return null;
        }

        $changes = $this->changes($currentValues, $candidate['values']);
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
            'proposed_values' => $candidate['values'],
            'changes' => $changes,
            'evidence' => $candidate['evidence'],
        ]);
    }

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
        $album = $file->albums->first();
        $payload = $this->values->metadataPayload($file);

        return [
            'title' => $this->values->cleanString($file->title),
            'artists' => $artists,
            'album' => $albums[0] ?? null,
            'duration_seconds' => $this->values->durationSeconds($file, $payload),
            'cover_url' => $this->coverResolver->forFile($file),
            'spotify_uri' => $this->spotifyUri($file),
            'track_number' => $this->values->cleanString($album?->pivot?->track_number ?? data_get($payload, 'audio.track_number')),
            'disc_number' => $this->values->cleanString($album?->pivot?->disc_number ?? data_get($payload, 'audio.disc_number')),
            'release_label' => $this->values->cleanString($album?->release_label),
            'catalog_number' => $this->values->cleanString($album?->catalog_number),
            'barcode' => $this->values->cleanString($album?->barcode),
            'release_date' => $this->values->cleanString($album?->release_date),
            'release_country' => $this->values->cleanString($album?->release_country),
            'isrc' => $this->values->cleanString(data_get($payload, 'isrc') ?? data_get($payload, 'audio.isrc')),
            'musicbrainz_recording_id' => $this->values->cleanString(data_get($payload, 'musicbrainz_recording_id') ?? data_get($payload, 'audio.musicbrainz_recording_id')),
            'musicbrainz_release_id' => $this->values->cleanString($album?->musicbrainz_release_id),
            'discogs_release_id' => $this->values->cleanString($album?->discogs_release_id),
        ];
    }

    private function localCandidate(File $file, array $currentValues, ?callable $progress = null): ?array
    {
        $candidates = [];

        $this->reportProgress($progress, 'fingerprint', 'Fingerprinting audio and checking AcoustID');
        $fingerprintCandidate = $this->fingerprintProvider->candidate($file);
        if ($fingerprintCandidate !== null) {
            $this->reportProgress($progress, 'ai_review', 'Reviewing fingerprint match');
            $fingerprintCandidate = $this->reviewFingerprintCandidate($file, $currentValues, $fingerprintCandidate);
        }

        $this->reportProgress($progress, 'cover_lookup', 'Searching existing album covers and MusicBrainz cover art');
        $coverCandidate = $this->coverLookup->candidate($file, $currentValues);

        $this->reportProgress($progress, 'discogs', 'Searching Discogs release data');
        $discogsCandidate = $this->discogsProvider->candidate($file, $currentValues);

        $this->reportProgress($progress, 'vgmdb', 'Searching VGMdb album metadata');
        $vgmdbCandidate = $this->vgmdbProvider->candidate($file, $currentValues, $fingerprintCandidate);

        if ($fingerprintCandidate !== null) {
            $fingerprintCandidate = $this->candidateEnricher->supplementWithCover($fingerprintCandidate, $coverCandidate);

            $fingerprintCandidate = $this->candidateEnricher->supplementWithDiscogs($file, $currentValues, $fingerprintCandidate, $discogsCandidate, 'acoustid_musicbrainz_discogs');

            $fingerprintCandidate = $this->vgmdbCandidates->merge(
                $fingerprintCandidate,
                $vgmdbCandidate,
                $fingerprintCandidate['provider'] === 'acoustid_musicbrainz_discogs'
                    ? 'acoustid_musicbrainz_discogs_vgmdb'
                    : 'acoustid_musicbrainz_vgmdb',
            );

            $candidates[] = $this->candidateEnricher->resolveAnomaly($file, $currentValues, $fingerprintCandidate);
        }

        if ($coverCandidate !== null) {
            $candidates[] = $coverCandidate['provider'] === 'existing_album_cover'
                ? $coverCandidate
                : $this->candidateEnricher->supplementWithDiscogs($file, $currentValues, $coverCandidate, $discogsCandidate, 'musicbrainz_discogs');
        }

        if ($discogsCandidate !== null) {
            $candidates[] = $discogsCandidate;
        }

        if ($vgmdbCandidate !== null) {
            $candidates[] = $vgmdbCandidate;
        }

        $tagCandidate = $this->localTags->candidate($file);
        if ($tagCandidate['values'] !== []) {
            $this->reportProgress($progress, 'embedded_tags', 'Reviewing embedded tags and AI search hints');
            $hasReviewableSourceCandidate = collect($candidates)
                ->contains(fn (array $candidate): bool => $this->changes($currentValues, $candidate['values']) !== []
                    && $this->candidateHasSourceReleaseSupport($candidate));

            $candidates[] = ! $hasReviewableSourceCandidate
                ? $this->candidateEnricher->resolveWithAiDiscogsSearch(
                    $file,
                    $currentValues,
                    $tagCandidate,
                    'local_ai_discogs',
                )
                : $tagCandidate;
        }

        $this->reportProgress($progress, 'scoring', 'Scoring metadata candidates');
        $reviewableCandidates = collect($candidates)
            ->filter(fn (array $candidate): bool => $this->changes($currentValues, $candidate['values']) !== [])
            ->sortByDesc(fn (array $candidate): int => $this->candidatePriority($candidate))
            ->values();

        if ($reviewableCandidates->isEmpty()) {
            return null;
        }

        $this->reportProgress($progress, 'ai_review', 'Reviewing field-level metadata safety');

        foreach ($reviewableCandidates as $candidate) {
            $reviewedCandidate = $this->fieldReviewer->review($file, $currentValues, $candidate, $this->changes($currentValues, $candidate['values']));
            if (is_array($reviewedCandidate) && $this->changes($currentValues, $reviewedCandidate['values']) !== []) {
                return $reviewedCandidate;
            }
        }

        return null;
    }

    private function reviewFingerprintCandidate(File $file, array $currentValues, array $candidate): ?array
    {
        if ($this->fingerprintCandidateHasIdentitySupport($currentValues, $candidate)) {
            return $candidate;
        }

        $changes = $this->changes($currentValues, $candidate['values']);
        if ($changes === []) {
            return null;
        }

        $review = $this->aiReviewer->review($file, $currentValues, $candidate, $changes);
        if (($review['verdict'] ?? null) !== 'accept') {
            return null;
        }

        $candidate['evidence']['ai_review'] = $review;
        if (is_numeric($review['confidence'] ?? null)) {
            $candidate['confidence'] = max(60, min(92, (int) round(((float) $review['confidence']) * 100)));
        }

        return $candidate;
    }

    private function fingerprintCandidateHasIdentitySupport(array $currentValues, array $candidate): bool
    {
        if (($candidate['provider'] ?? null) !== 'acoustid_musicbrainz') {
            return true;
        }

        return in_array($candidate['evidence']['identity_support'] ?? null, ['matched_existing_identity', 'release_with_cover', 'strong_fingerprint_release'], true);
    }

    private function candidateHasSourceReleaseSupport(array $candidate): bool
    {
        if (! str_starts_with((string) ($candidate['provider'] ?? ''), 'acoustid_musicbrainz')) {
            return true;
        }

        if (($candidate['evidence']['ai_review']['source_identity_supported'] ?? false) === true) {
            return true;
        }

        if (($candidate['evidence']['release_consistency_review']['verdict'] ?? null) === 'accept') {
            return true;
        }

        if (in_array($candidate['evidence']['identity_support'] ?? null, ['strong_fingerprint_release', 'release_with_cover'], true)) {
            return true;
        }

        return in_array('album', $this->values->cleanStringList($candidate['evidence']['matched_existing_fields'] ?? []), true);
    }

    private function candidatePriority(array $candidate): int
    {
        $providerPriority = match ($candidate['provider']) {
            'acoustid_musicbrainz_discogs_vgmdb' => 325,
            'acoustid_musicbrainz_ai_discogs' => $this->values->cleanString($candidate['evidence']['discogs_track_position'] ?? null) !== null ? 320 : 225,
            'acoustid_musicbrainz_vgmdb' => 315,
            'acoustid_musicbrainz_discogs' => 310,
            'acoustid_musicbrainz' => $this->candidateHasSourceReleaseSupport($candidate) ? 300 : 230,
            'musicbrainz_discogs' => 245,
            'discogs_release' => $this->candidateHasStrongDiscogsReleaseSupport($candidate) ? 330 : 235,
            'existing_album_cover' => 230,
            'vgmdb_album' => 225,
            'musicbrainz_cover_art' => 220,
            'spotify' => 250,
            'local_ai_discogs' => 310,
            default => 100,
        };

        return $providerPriority + (int) $candidate['confidence'];
    }

    private function candidateHasStrongDiscogsReleaseSupport(array $candidate): bool
    {
        $durationDelta = $candidate['evidence']['duration_delta_seconds'] ?? null;
        $matchedFields = $this->values->cleanStringList($candidate['evidence']['matched_existing_fields'] ?? []);

        return ($candidate['provider'] ?? null) === 'discogs_release'
            && $this->values->cleanString($candidate['values']['discogs_release_id'] ?? null) !== null
            && $this->values->cleanString($candidate['evidence']['track_position'] ?? null) !== null
            && is_numeric($durationDelta)
            && (float) $durationDelta <= 2.0
            && ! in_array('album', $matchedFields, true)
            && ! in_array('release_title', $matchedFields, true)
            && in_array('artists', $matchedFields, true)
            && in_array('track', $matchedFields, true)
            && in_array('duration', $matchedFields, true);
    }

    private function spotifyCandidate(File $file, User $user, ?callable $progress = null): array
    {
        $trackId = $this->spotifyTrackId((string) $file->source_id)
            ?? $this->spotifyTrackId((string) $file->url)
            ?? $this->spotifyTrackId((string) $file->referrer_url);
        $track = null;
        $evidence = ['source' => 'spotify', 'track_id' => $trackId, 'refetched' => false];

        if ($trackId !== null) {
            $this->reportProgress($progress, 'spotify', 'Refreshing Spotify metadata');
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

    private function spotifyValues(array $track): array
    {
        $values = [];
        $this->putIfPresent($values, 'title', $this->values->cleanString(data_get($track, 'name')));
        $this->putIfPresent($values, 'artists', $this->values->cleanStringList(data_get($track, 'artists.*.name', [])));
        $this->putIfPresent($values, 'album', $this->values->cleanString(data_get($track, 'album.name')));
        $this->putIfPresent($values, 'spotify_uri', $this->values->cleanString(data_get($track, 'uri')));
        $this->putIfPresent($values, 'isrc', $this->values->cleanString(data_get($track, 'external_ids.isrc')));
        $this->putIfPresent($values, 'cover_url', $this->bestSpotifyCoverUrl(data_get($track, 'album.images', [])));
        $this->putIfPresent($values, 'track_number', $this->values->cleanString(data_get($track, 'track_number')));
        $this->putIfPresent($values, 'disc_number', $this->values->cleanString(data_get($track, 'disc_number')));
        $this->putIfPresent($values, 'release_date', $this->values->cleanString(data_get($track, 'album.release_date')));

        $duration = $this->values->positiveInteger(data_get($track, 'duration_ms'));
        if ($duration !== null) {
            $values['duration_seconds'] = (int) round($duration / 1000);
        }

        return $values;
    }

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

        foreach (self::REVIEW_FIELDS as $field) {
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

    private function withoutAliasValues(array $values): array
    {
        return array_diff_key($values, array_flip(['title_aliases', 'artist_aliases', 'album_aliases', 'artist_alias_map']));
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

    private function reportProgress(?callable $progress, string $step, string $label): void
    {
        if ($progress === null) {
            return;
        }

        $progress($step, $label);
    }
}
