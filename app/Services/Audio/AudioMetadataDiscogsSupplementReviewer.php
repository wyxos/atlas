<?php

namespace App\Services\Audio;

use App\Models\File;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AudioMetadataDiscogsSupplementReviewer
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

    private const DISCOGS_RELEASE_PACKAGE_FIELDS = [
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
        'discogs_release_id',
    ];

    public function __construct(
        private readonly AudioMetadataValueExtractor $values,
    ) {}

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $discogsCandidate
     * @return array{0:array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>},1:string}
     */
    public function review(File $file, array $currentValues, array $candidate, array $discogsCandidate, string $provider): array
    {
        if (! $this->needsReview($currentValues, $discogsCandidate)) {
            return [$discogsCandidate, $provider];
        }

        $review = $this->reviewWithAi($file, $currentValues, $candidate, $discogsCandidate);
        if (($review['verdict'] ?? null) === 'accept') {
            $discogsCandidate['evidence']['release_consistency_review'] = $review;

            return [$discogsCandidate, $this->aiDiscogsProvider($provider)];
        }

        $safeFields = $this->safeFields($review['safe_fields'] ?? []);
        $discogsCandidate['values'] = array_intersect_key($discogsCandidate['values'], array_flip($safeFields));

        if ($review !== null) {
            $discogsCandidate['evidence']['release_consistency_review'] = $review;

            return [$discogsCandidate, $this->aiDiscogsProvider($provider)];
        }

        return [$discogsCandidate, $provider];
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $discogsCandidate
     */
    private function needsReview(array $currentValues, array $discogsCandidate): bool
    {
        $currentAlbum = $this->values->cleanString($currentValues['album'] ?? null);
        $proposedAlbum = $this->values->cleanString($discogsCandidate['values']['album'] ?? $discogsCandidate['evidence']['discogs_release_title'] ?? null);

        return $currentAlbum !== null
            && $proposedAlbum !== null
            && $this->normalizedIdentity($currentAlbum) !== $this->normalizedIdentity($proposedAlbum);
    }

    /**
     * @param  array<string, mixed>  $currentValues
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $candidate
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $discogsCandidate
     * @return array{verdict:string,reason:string,model:string|null,safe_fields:list<string>}|null
     */
    private function reviewWithAi(File $file, array $currentValues, array $candidate, array $discogsCandidate): ?array
    {
        $baseUrl = rtrim((string) config('services.audio_metadata.ai_base_url'), '/');
        if (! $this->enabled($baseUrl)) {
            return null;
        }

        $input = $this->input($file, $currentValues, $candidate, $discogsCandidate);

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

    private function enabled(string $baseUrl): bool
    {
        return (bool) config('services.audio_metadata.ai_enabled', true) && $baseUrl !== '';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function reviewWithGateway(string $baseUrl, array $input): array
    {
        $request = Http::timeout((int) config('services.audio_metadata.ai_timeout_seconds', 90))->acceptJson()->asJson();
        $token = (string) config('services.audio_metadata.ai_token', '');
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $response = $request->post($baseUrl.'/v1/audio/metadata-review', [
            'model' => config('services.audio_metadata.ai_model'),
            'schemaVersion' => 'atlas-audio-metadata-discogs-supplement-v1',
            'input' => $input,
            'prompt' => $this->prompt($input),
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
    private function reviewWithOllama(string $baseUrl, array $input): array
    {
        $response = Http::timeout((int) config('services.audio_metadata.ai_timeout_seconds', 90))->acceptJson()->asJson()->post($baseUrl.'/api/chat', [
            'model' => config('services.audio_metadata.ai_model'),
            'stream' => false,
            'format' => 'json',
            'messages' => [
                ['role' => 'system', 'content' => 'You review music metadata candidates. Use only supplied JSON evidence. Return strict JSON only.'],
                ['role' => 'user', 'content' => $this->prompt($input)],
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
     * @param  array{provider:string,confidence:int,values:array<string, mixed>,evidence:array<string, mixed>}  $discogsCandidate
     * @return array<string, mixed>
     */
    private function input(File $file, array $currentValues, array $candidate, array $discogsCandidate): array
    {
        return [
            'file' => [
                'title' => $this->values->cleanString($file->title),
                'filename' => $this->values->cleanString($file->filename),
                'source' => $this->values->cleanString($file->source),
                'mime_type' => $this->values->cleanString($file->mime_type),
            ],
            'current_values' => Arr::only($currentValues, self::REVIEW_FIELDS),
            'fingerprint_candidate' => [
                'provider' => $candidate['provider'],
                'values' => Arr::only($candidate['values'], self::REVIEW_FIELDS),
                'evidence' => Arr::except($candidate['evidence'], ['fingerprint', 'raw_fingerprint']),
            ],
            'discogs_candidate' => [
                'provider' => $discogsCandidate['provider'],
                'values' => Arr::only($discogsCandidate['values'], self::REVIEW_FIELDS),
                'evidence' => $discogsCandidate['evidence'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,reason:string,model:string|null,safe_fields:list<string>}
     */
    private function normalizeResponse(array $payload): array
    {
        $verdict = mb_strtolower($this->values->cleanString($payload['verdict'] ?? null) ?? 'ambiguous');
        if (! in_array($verdict, ['accept', 'reject', 'ambiguous'], true)) {
            $verdict = 'ambiguous';
        }

        return [
            'verdict' => $verdict,
            'reason' => mb_substr($this->values->cleanString($payload['reason'] ?? null) ?? 'No reason returned.', 0, 240),
            'model' => $this->values->cleanString($payload['model'] ?? config('services.audio_metadata.ai_model')),
            'safe_fields' => $this->safeFields(is_array($payload['safe_fields'] ?? null) ? $payload['safe_fields'] : []),
        ];
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function safeFields(array $fields): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $field): ?string => $this->values->cleanString($field), $fields),
            fn (?string $field): bool => $field !== null && in_array($field, self::DISCOGS_RELEASE_PACKAGE_FIELDS, true),
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function prompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","reason":"short reason","safe_fields":["title"]}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Decide whether the Discogs release package is consistent with the current album, edition, disc, and collection context.',
            'Use accept only when release-level fields such as album, disc number, track number, label, barcode, country, cover, and Discogs release ID can safely describe this file.',
            'Use reject or ambiguous when the recording/track may match but the Discogs release appears to be a different edition, compilation, soundtrack variant, or disc than the current collection.',
            'When rejecting or marking ambiguous, safe_fields must contain only fields that can still be safely copied from Discogs without importing the wrong release package. Return an empty array when none are safe.',
            'Do not invent metadata. Judge only the supplied JSON evidence.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function aiDiscogsProvider(string $provider): string
    {
        if (str_contains($provider, '_ai_discogs')) {
            return $provider;
        }

        return str_contains($provider, '_discogs')
            ? str_replace('_discogs', '_ai_discogs', $provider)
            : $provider.'_ai_discogs';
    }

    private function normalizedIdentity(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }
}
