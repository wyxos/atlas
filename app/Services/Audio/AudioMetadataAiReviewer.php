<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AudioMetadataAiReviewer
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
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null}|null
     */
    public function review(File $file, array $currentValues, array $candidate, array $changes): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return null;
        }

        $input = $this->input($file, $currentValues, $candidate, $changes);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input),
                default => $this->reviewWithGateway($baseUrl, $input),
            };

            return $this->normalizeResponse($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, mixed>  $source
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,source_identity_supported:bool,selected_track_position:string|null,selected_track_title:string|null}|null
     */
    public function resolveAnomaly(File $file, array $currentValues, array $candidate, array $source): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return null;
        }

        $input = $this->anomalyInput($file, $currentValues, $candidate, $source);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input, $this->anomalyPrompt($input)),
                default => $this->reviewWithGateway($baseUrl, $input, $this->anomalyPrompt($input), 'atlas-audio-metadata-anomaly-v1'),
            };

            return $this->normalizeAnomalyResponse($payload);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,safe_fields:list<string>}|null
     */
    public function reviewFields(File $file, array $currentValues, array $candidate, array $changes): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return null;
        }

        $input = $this->input($file, $currentValues, $candidate, $changes);

        $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
            'ollama' => $this->reviewWithOllama($baseUrl, $input, $this->fieldReviewPrompt($input)),
            default => $this->reviewWithGateway($baseUrl, $input, $this->fieldReviewPrompt($input), 'atlas-audio-metadata-field-adjudication-v1'),
        };

        return $this->normalizeFieldReviewResponse($payload);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return list<array{release_title:string,artist:string,reason:string|null}>
     */
    public function discogsSearchQueries(File $file, array $currentValues, array $candidate): array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return [];
        }

        $input = $this->discogsSearchInput($file, $currentValues, $candidate);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input, $this->discogsSearchPrompt($input)),
                default => $this->reviewWithGateway($baseUrl, $input, $this->discogsSearchPrompt($input), 'atlas-audio-metadata-discogs-search-v1'),
            };

            return $this->normalizeDiscogsSearchQueries($payload);
        } catch (Throwable) {
            return [];
        }
    }

    public function enabled(): bool
    {
        return (bool) config('services.audio_metadata.ai_enabled', true)
            && trim((string) config('services.audio_metadata.ai_base_url', '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function reviewWithGateway(string $baseUrl, array $input, ?string $prompt = null, string $schemaVersion = 'atlas-audio-metadata-review-v1'): array
    {
        $request = Http::timeout((int) config('services.audio_metadata.ai_timeout_seconds', 90))
            ->acceptJson()
            ->asJson();

        $token = (string) config('services.audio_metadata.ai_token', '');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($baseUrl.'/v1/audio/metadata-review', [
            'model' => config('services.audio_metadata.ai_model'),
            'schemaVersion' => $schemaVersion,
            'input' => $input,
            'prompt' => $prompt ?? $this->prompt($input),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI gateway returned HTTP '.$response->status().'.');
        }

        return $this->jsonPayload($response->json());
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function reviewWithOllama(string $baseUrl, array $input, ?string $prompt = null): array
    {
        $response = Http::timeout((int) config('services.audio_metadata.ai_timeout_seconds', 90))
            ->acceptJson()
            ->asJson()
            ->post($baseUrl.'/api/chat', [
                'model' => config('services.audio_metadata.ai_model'),
                'stream' => false,
                'format' => 'json', 'options' => ['temperature' => 0, 'seed' => 1],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You review music metadata candidates. Use only supplied JSON evidence. Return strict JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt ?? $this->prompt($input),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Ollama returned HTTP '.$response->status().'.');
        }

        return $this->jsonPayload($response->json());
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return array<string, mixed>
     */
    private function input(File $file, array $currentValues, array $candidate, array $changes): array
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
            'candidate' => [
                'provider' => $candidate['provider'],
                'confidence' => $candidate['confidence'],
                'values' => Arr::only($candidate['values'], self::REVIEW_FIELDS),
                'evidence' => Arr::except($candidate['evidence'], ['fingerprint', 'raw_fingerprint']),
            ],
            'changes' => $changes,
        ];
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function anomalyInput(File $file, array $currentValues, array $candidate, array $source): array
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
            'current_values' => $currentValues,
            'fingerprint_candidate' => [
                'provider' => $candidate['provider'],
                'confidence' => $candidate['confidence'],
                'values' => $candidate['values'],
                'evidence' => Arr::except($candidate['evidence'], ['fingerprint', 'raw_fingerprint']),
            ],
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @return array<string, mixed>
     */
    private function discogsSearchInput(File $file, array $currentValues, array $candidate): array
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
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,source_identity_supported:bool,selected_track_position:string|null,selected_track_title:string|null}
     */
    private function normalizeAnomalyResponse(array $payload): array
    {
        return [
            ...$this->normalizeResponse($payload),
            'source_identity_supported' => ($payload['source_identity_supported'] ?? null) === true,
            'selected_track_position' => $this->cleanString($payload['selected_track_position'] ?? null),
            'selected_track_title' => $this->cleanString($payload['selected_track_title'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,safe_fields:list<string>}
     */
    private function normalizeFieldReviewResponse(array $payload): array
    {
        return [
            ...$this->normalizeResponse($payload),
            'safe_fields' => $this->safeFields(is_array($payload['safe_fields'] ?? null) ? $payload['safe_fields'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{release_title:string,artist:string,reason:string|null}>
     */
    private function normalizeDiscogsSearchQueries(array $payload): array
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

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['message']['content']) && is_string($payload['message']['content'])) {
            return $this->decodeJson($payload['message']['content']);
        }

        if (is_array($payload) && isset($payload['response']) && is_string($payload['response'])) {
            return $this->decodeJson($payload['response']);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('AI response was not JSON.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AI response JSON could not be decoded.');
        }

        return $decoded;
    }

    private function prompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","confidence":0.82,"reason":"short reason"}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Use accept only when the candidate is very likely the same recording or a clearly better cover for the same album.',
            'Use reject when the only supporting evidence is duration, or when title/artist/album/source hints point to a different work.',
            'Use ambiguous when evidence is plausible but insufficient for a reviewable proposal.',
            'Do not invent new metadata. Judge only the candidate values already supplied.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function anomalyPrompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","confidence":0.82,"reason":"short reason","source_identity_supported":true,"selected_track_position":"2","selected_track_title":"source track title"}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Use accept only when the fingerprint candidate and source release are likely the same recording/release, and one listed source track plausibly represents the current track under another language, romanization, or import/custom title.',
            'Set source_identity_supported to true only when the supplied release and selected source track are coherent with the current title, artists, album, filename, duration, and fingerprint candidate. Set it to false for merely similar album names, unrelated artists, unrelated selected tracks, or weak search-result matches.',
            'Use reject when the source release or selected track points to a different work.',
            'Use ambiguous when the listed evidence is plausible but insufficient.',
            'Do not invent canonical titles, artists, albums, release details, IDs, or track positions. Select one track from the supplied source.tracklist only.',
            'Canonical/source fields should be original/source values from the selected release or track.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function discogsSearchPrompt(array $input): string
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

    private function fieldReviewPrompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"ambiguous","confidence":0.82,"reason":"short reason","safe_fields":[]}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'You are judging field-level safety for an Atlas audio metadata proposal.',
            'Use only the supplied JSON evidence. Do not invent metadata and do not repair values.',
            'safe_fields must be a subset of candidate.values keys. Never include a field that is absent from candidate.values, even if it is shown in examples or current_values.',
            'A strong fingerprint can prove a recording while still failing to prove the correct release, album, edition, disc, cover, label, catalog number, barcode, country, or release date.',
            'Include a field in safe_fields only when that exact field is coherent with the current title, artist, album/group, filename, duration, and provider evidence.',
            'Title is unsafe when the current and proposed titles have different remix, mix, version, update, edit, live, remaster, vinyl, or edition descriptors. Title case, bracket-vs-parenthesis style, apostrophes, punctuation, and whitespace alone are safe when normalized title tokens and mix descriptors match.',
            'Album, cover_url, track_number, disc_number, release_label, catalog_number, barcode, release_date, release_country, musicbrainz_release_id, and discogs_release_id require release-level confidence, not only recording confidence.',
            'Compare track title only to candidate title. Compare current album only to candidate album or source release title. Do not compare the track title to the album title.',
            'Use ambiguous with a reduced safe_fields list when the recording likely matches but release-level details may describe a different single, remix package, compilation, edition, or disc.',
            'Use reject with an empty safe_fields list when even recording identity is not coherent.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n".'Hard rule: For provider discogs_release with discogs_release_id, track_position, duration_delta_seconds <= 2, and matched_existing_fields containing artists, track, and duration, return accept unless current title, current artists, or current duration contradict candidate.values. Missing or different release-only current fields such as album, cover_url, track_number, release_label, catalog_number, barcode, release_date, release_country, and discogs_release_id are proposed corrections, not conflict evidence. Under this hard Discogs rule, safe_fields must include every one of these candidate.values keys when present: album, cover_url, track_number, release_label, catalog_number, release_date, release_country, discogs_release_id.',
        ]);
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
