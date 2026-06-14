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

    public function __construct(
        private readonly AudioMetadataDiscogsAiReviewPayloads $discogsPayloads,
        private readonly AudioMetadataAiReviewPrompts $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array<string, array{current:mixed,proposed:mixed}>  $changes
     * @return array{verdict:string,reason:string,model:string|null}|null
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
     * @return array{verdict:string,reason:string,model:string|null,source_identity_supported:bool,selected_track_position:string|null,selected_track_title:string|null}|null
     */
    public function resolveAnomaly(File $file, array $currentValues, array $candidate, array $source): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return null;
        }

        $input = $this->anomalyInput($file, $currentValues, $candidate, $source);
        $prompt = $this->prompts->anomaly($input);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input, $prompt),
                default => $this->reviewWithGateway($baseUrl, $input, $prompt, 'atlas-audio-metadata-anomaly-v1'),
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
     * @return array{verdict:string,reason:string,model:string|null,safe_fields:list<string>,field_reviews:array<string, array{verdict:string,reason:string}>}|null
     */
    public function reviewFields(File $file, array $currentValues, array $candidate, array $changes): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '') {
            return null;
        }

        $input = $this->input($file, $currentValues, $candidate, $changes);
        $prompt = $this->prompts->fieldReview($input);

        $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
            'ollama' => $this->reviewWithOllama($baseUrl, $input, $prompt),
            default => $this->reviewWithGateway($baseUrl, $input, $prompt, 'atlas-audio-metadata-field-adjudication-v1'),
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

        $input = $this->discogsPayloads->searchInput($file, $currentValues, $candidate);
        $prompt = $this->discogsPayloads->searchPrompt($input);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input, $prompt),
                default => $this->reviewWithGateway($baseUrl, $input, $prompt, 'atlas-audio-metadata-discogs-search-v1'),
            };

            return $this->discogsPayloads->normalizeSearchQueries($payload);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  list<array<string, mixed>>  $candidates
     * @return array{verdict:string,reason:string,model:string|null,selected_release_id:string|null,selected_track_position:string|null,safe_fields:list<string>,rejected_candidates:list<array{release_id:string|null,reason:string|null}>}|null
     */
    public function adjudicateDiscogsRelease(File $file, array $currentValues, array $candidates): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled() || $baseUrl === '' || $candidates === []) {
            return null;
        }

        $input = $this->discogsPayloads->releaseAdjudicationInput($file, $currentValues, $candidates);
        $prompt = $this->discogsPayloads->releaseAdjudicationPrompt($input);

        try {
            $payload = match ((string) config('services.audio_metadata.ai_driver', 'gateway')) {
                'ollama' => $this->reviewWithOllama($baseUrl, $input, $prompt),
                default => $this->reviewWithGateway($baseUrl, $input, $prompt, 'atlas-audio-metadata-discogs-release-adjudication-v1'),
            };

            return $this->discogsPayloads->normalizeReleaseAdjudicationResponse($payload, $candidates);
        } catch (Throwable) {
            return null;
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
            'prompt' => $prompt ?? $this->prompts->review($input),
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
                        'content' => $prompt ?? $this->prompts->review($input),
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
                'values' => $candidate['values'],
                'evidence' => Arr::except($candidate['evidence'], ['fingerprint', 'raw_fingerprint']),
            ],
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,reason:string,model:string|null}
     */
    private function normalizeResponse(array $payload): array
    {
        return [
            'verdict' => $this->verdict($payload['verdict'] ?? null),
            'reason' => mb_substr($this->usableReason($payload['reason'] ?? null) ?? 'AI did not return a usable review summary.', 0, 240),
            'model' => $this->cleanString($payload['model'] ?? config('services.audio_metadata.ai_model')),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,reason:string,model:string|null,source_identity_supported:bool,selected_track_position:string|null,selected_track_title:string|null}
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
     * @return array{verdict:string,reason:string,model:string|null,safe_fields:list<string>,field_reviews:array<string, array{verdict:string,reason:string}>}
     */
    private function normalizeFieldReviewResponse(array $payload): array
    {
        $fieldReviews = $this->fieldReviews($payload['field_reviews'] ?? []);

        return [
            ...$this->normalizeResponse($payload),
            'safe_fields' => $this->safeFieldsForReview($payload, $fieldReviews),
            'field_reviews' => $fieldReviews,
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{verdict:string,reason:string}>  $fieldReviews
     * @return list<string>
     */
    private function safeFieldsForReview(array $payload, array $fieldReviews): array
    {
        $safeFields = $this->safeFields(is_array($payload['safe_fields'] ?? null) ? $payload['safe_fields'] : []);
        if ($fieldReviews === []) {
            return $safeFields;
        }

        $acceptedFields = array_keys(array_filter(
            $fieldReviews,
            fn (array $review): bool => $review['verdict'] === 'accept',
        ));
        $uncontradictedSafeFields = array_values(array_filter(
            $safeFields,
            fn (string $field): bool => ! isset($fieldReviews[$field]) || $fieldReviews[$field]['verdict'] === 'accept',
        ));

        return array_values(array_unique([...$acceptedFields, ...$uncontradictedSafeFields]));
    }

    /**
     * @return array<string, array{verdict:string,reason:string}>
     */
    private function fieldReviews(mixed $reviews): array
    {
        if (! is_array($reviews)) {
            return [];
        }

        $normalized = [];
        foreach ($reviews as $field => $review) {
            $field = $this->cleanString($field);
            if ($field === null || ! in_array($field, self::REVIEW_FIELDS, true) || ! is_array($review)) {
                continue;
            }

            $verdict = $this->verdict($review['verdict'] ?? null);
            $normalized[$field] = [
                'verdict' => $verdict,
                'reason' => mb_substr($this->usableReason($review['reason'] ?? null) ?? $this->missingFieldReason($verdict), 0, 240),
            ];
        }

        return $normalized;
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

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $clean = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';

        return $clean !== '' ? $clean : null;
    }

    private function usableReason(mixed $value): ?string
    {
        $reason = $this->cleanString($value);
        if ($reason === null) {
            return null;
        }

        $placeholder = preg_replace('/[^a-z]+/', ' ', mb_strtolower($reason)) ?? '';

        return in_array(trim($placeholder), ['short reason', 'short summary', 'field specific reason'], true) ? null : $reason;
    }

    private function missingFieldReason(string $verdict): string
    {
        return match ($verdict) {
            'accept' => 'AI accepted this field but did not return a field-specific reason.',
            'reject' => 'AI rejected this field but did not return a field-specific reason.',
            default => 'AI marked this field ambiguous but did not return a field-specific reason.',
        };
    }

    private function verdict(mixed $value): string
    {
        $verdict = mb_strtolower($this->cleanString($value) ?? 'ambiguous');

        return in_array($verdict, ['accept', 'reject', 'ambiguous'], true) ? $verdict : 'ambiguous';
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
