<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Arr;

class AudioMetadataDiscogsAiReviewPayloads
{
    private const REVIEW_FIELDS = [
        'title',
        'artists',
        'album',
        'duration_seconds',
        'cover_url',
        'track_number',
        'disc_number',
        'release_label',
        'catalog_number',
        'barcode',
        'release_date',
        'release_country',
        'isrc',
        'musicbrainz_recording_id',
        'musicbrainz_release_id',
        'discogs_release_id',
    ];

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array<string, mixed>
     */
    public function searchInput(File $file, array $currentValues, array $candidate): array
    {
        return [
            'file' => [
                'title' => $this->cleanString($file->title),
                'filename' => $this->cleanString($file->filename),
                'source' => $this->cleanString($file->source),
                'mime_type' => $this->cleanString($file->mime_type),
            ],
            'current_values' => [
                'title' => $currentValues['title'] ?? null,
                'artists' => $currentValues['artists'] ?? [],
                'album' => $currentValues['album'] ?? null,
                'duration_seconds' => $currentValues['duration_seconds'] ?? null,
            ],
            'fingerprint_candidate' => [
                'provider' => $candidate['provider'],
                'confidence' => $candidate['confidence'],
                'values' => Arr::only($candidate['values'], self::REVIEW_FIELDS),
                'evidence' => Arr::except($candidate['evidence'], ['fingerprint', 'raw_fingerprint']),
            ],
        ];
    }

    public function searchPrompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"queries":[{"release_title":"album or release search title","artist":"artist search name","reason":"short reason"}],"model":"model-name"}.',
            'Suggest at most 5 Discogs release searches that could retrieve the source release for this audio track.',
            'Use current values, filename hints, and fingerprint candidate values to bridge romanization, translation, alternate spellings, import titles, soundtrack numbering, and source/original language differences.',
            'Do not invent final metadata. These are search queries only.',
            'Prefer concise release titles and likely Discogs artist spellings.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{release_title:string,artist:string,reason:string|null}>
     */
    public function normalizeSearchQueries(array $payload): array
    {
        $queries = $payload['queries'] ?? $payload['search_queries'] ?? [];
        if (! is_array($queries)) {
            return [];
        }

        $normalized = [];
        foreach ($queries as $query) {
            if (! is_array($query)) {
                continue;
            }

            $releaseTitle = $this->cleanString($query['release_title'] ?? $query['album'] ?? null);
            $artist = $this->cleanString($query['artist'] ?? null);
            if ($releaseTitle === null || $artist === null) {
                continue;
            }

            $key = mb_strtolower($releaseTitle).'|'.mb_strtolower($artist);
            $normalized[$key] = [
                'release_title' => $releaseTitle,
                'artist' => $artist,
                'reason' => $this->cleanString($query['reason'] ?? null),
            ];
        }

        return array_slice(array_values($normalized), 0, 5);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public function releaseAdjudicationInput(File $file, array $currentValues, array $candidates): array
    {
        return [
            'file' => [
                'title' => $this->cleanString($file->title),
                'filename' => $this->cleanString($file->filename),
                'source' => $this->cleanString($file->source),
                'mime_type' => $this->cleanString($file->mime_type),
                'url_host' => $this->host((string) $file->url),
                'referrer_host' => $this->host((string) $file->referrer_url),
            ],
            'current_values' => Arr::only($currentValues, self::REVIEW_FIELDS),
            'candidates' => $this->candidateSummaries($candidates),
        ];
    }

    public function releaseAdjudicationPrompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"ambiguous","confidence":0.82,"reason":"short reason","selected_release_id":null,"selected_track_position":null,"safe_fields":[],"rejected_candidates":[{"release_id":"456","reason":"short reason"}],"model":"model-name"}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'You are choosing the correct Discogs release and matched track for an Atlas audio metadata proposal.',
            'Use only the supplied JSON evidence. Do not invent releases, tracks, labels, dates, countries, IDs, covers, or safe fields.',
            'Choose selected_release_id only from each supplied candidate release_id, discogs_release_id, or id value.',
            'Choose selected_track_position only from the selected candidate matched_track, track, tracklist, tracks, or top-level position fields.',
            'Exact title, artist, and duration can prove recording identity, but they do not by themselves prove edition, format, disc, label, barcode, catalog number, country, release date, or cover.',
            'Prefer the candidate whose format, track position, disc/track numbering, release date, country, label/catalog/barcode, and album/filename context are most coherent with current_values and file evidence.',
            'If current_values or filename contains edition context such as CD, disc, volume, deluxe, special edition, vinyl, file, digital, bonus, or remaster, weigh it as context for choosing between supplied candidates without inventing new values.',
            'If verdict is accept, selected_release_id and selected_track_position must be non-null.',
            'safe_fields must be a subset of the selected candidate values keys that are safe to propose from the chosen release and track.',
            'rejected_candidates must only list supplied candidate release IDs that are ruled out by evidence.',
            'Use ambiguous when multiple candidates remain plausible or when the correct release cannot be chosen from supplied evidence.',
            'Use reject when none of the supplied candidates coherently match the current recording and release context.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $candidates
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,selected_release_id:string|null,selected_track_position:string|null,safe_fields:list<string>,rejected_candidates:list<array{release_id:string|null,reason:string|null}>}
     */
    public function normalizeReleaseAdjudicationResponse(array $payload, array $candidates): array
    {
        $review = $this->normalizeResponse($payload);
        $candidatesById = $this->candidatesById($candidates);
        $selectedReleaseId = $this->cleanString($payload['selected_release_id'] ?? null);

        if ($selectedReleaseId !== null && ! isset($candidatesById[$selectedReleaseId])) {
            $selectedReleaseId = null;
        }

        $selectedCandidate = $selectedReleaseId === null ? null : $candidatesById[$selectedReleaseId];
        $selectedTrackPosition = $this->cleanString($payload['selected_track_position'] ?? null);
        if ($selectedCandidate === null || ! $this->candidateHasTrackPosition($selectedCandidate, $selectedTrackPosition)) {
            $selectedTrackPosition = null;
        }

        if ($review['verdict'] === 'accept' && ($selectedReleaseId === null || $selectedTrackPosition === null)) {
            $review['verdict'] = 'ambiguous';
        }

        return [
            ...$review,
            'selected_release_id' => $selectedReleaseId,
            'selected_track_position' => $selectedTrackPosition,
            'safe_fields' => $this->adjudicationSafeFields(
                is_array($payload['safe_fields'] ?? null) ? $payload['safe_fields'] : [],
                $selectedCandidate,
            ),
            'rejected_candidates' => $this->rejectedCandidates($payload['rejected_candidates'] ?? [], array_keys($candidatesById)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private function candidateSummaries(array $candidates): array
    {
        return array_values(array_map(
            fn (array $candidate): array => Arr::except($candidate, ['fingerprint', 'raw_fingerprint']),
            $candidates,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, array<string, mixed>>
     */
    private function candidatesById(array $candidates): array
    {
        $byId = [];
        foreach ($candidates as $candidate) {
            $releaseId = $this->candidateId($candidate);
            if ($releaseId !== null && ! isset($byId[$releaseId])) {
                $byId[$releaseId] = $candidate;
            }
        }

        return $byId;
    }

    private function candidateId(mixed $candidate): ?string
    {
        if (is_array($candidate)) {
            return $this->cleanString($candidate['release_id'] ?? $candidate['discogs_release_id'] ?? $candidate['id'] ?? null);
        }

        return $this->cleanString($candidate);
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function candidateHasTrackPosition(array $candidate, ?string $position): bool
    {
        if ($position === null) {
            return false;
        }

        foreach ([$candidate['matched_track'] ?? null, $candidate['track'] ?? null, $candidate] as $track) {
            if (! is_array($track)) {
                continue;
            }

            $trackPosition = $this->cleanString($track['position'] ?? $track['track_position'] ?? $track['number'] ?? null);
            if ($position === $trackPosition) {
                return true;
            }
        }

        $tracks = $candidate['tracklist'] ?? $candidate['tracks'] ?? [];
        if (! is_array($tracks)) {
            return false;
        }

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            $trackPosition = $this->cleanString($track['position'] ?? $track['track_position'] ?? $track['number'] ?? null);
            if ($position === $trackPosition) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $fields
     * @param  array<string, mixed>|null  $selectedCandidate
     * @return list<string>
     */
    private function adjudicationSafeFields(array $fields, ?array $selectedCandidate): array
    {
        $safeFields = $this->safeFields($fields);
        if ($selectedCandidate === null || ! is_array($selectedCandidate['values'] ?? null)) {
            return [];
        }

        $candidateFields = array_flip(array_keys($selectedCandidate['values']));

        return array_values(array_filter(
            $safeFields,
            fn (string $field): bool => isset($candidateFields[$field]),
        ));
    }

    /**
     * @param  list<string>  $validReleaseIds
     * @return list<array{release_id:string|null,reason:string|null}>
     */
    private function rejectedCandidates(mixed $candidates, array $validReleaseIds): array
    {
        if (! is_array($candidates)) {
            return [];
        }

        $validReleaseIds = array_flip($validReleaseIds);
        $normalized = [];
        foreach ($candidates as $candidate) {
            $releaseId = $this->candidateId($candidate);
            if ($releaseId === null || ! isset($validReleaseIds[$releaseId])) {
                continue;
            }

            $reason = is_array($candidate)
                ? mb_substr($this->cleanString($candidate['reason'] ?? null) ?? '', 0, 180)
                : '';

            $normalized[$releaseId] = [
                'release_id' => $releaseId,
                'reason' => $reason !== '' ? $reason : null,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null}
     */
    private function normalizeResponse(array $payload): array
    {
        $verdict = mb_strtolower($this->cleanString($payload['verdict'] ?? null) ?? 'ambiguous');
        if (! in_array($verdict, ['accept', 'reject', 'ambiguous'], true)) {
            $verdict = 'ambiguous';
        }

        $confidence = $payload['confidence'] ?? null;

        return [
            'verdict' => $verdict,
            'confidence' => is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null,
            'reason' => mb_substr($this->cleanString($payload['reason'] ?? null) ?? 'No reason returned.', 0, 240),
            'model' => $this->cleanString($payload['model'] ?? config('services.audio_metadata.ai_model')),
        ];
    }

    /**
     * @param  list<mixed>  $fields
     * @return list<string>
     */
    private function safeFields(array $fields): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $field): ?string => $this->cleanString($field), $fields),
            fn (?string $field): bool => $field !== null && in_array($field, self::REVIEW_FIELDS, true),
        ));
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
