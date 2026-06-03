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
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,selected_track_position:string|null,selected_track_title:string|null,title_aliases:list<string>,artist_aliases:list<string>,album_aliases:list<string>}|null
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
                'format' => 'json',
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
     * @return array{verdict:string,confidence:float|null,reason:string,model:string|null,selected_track_position:string|null,selected_track_title:string|null,title_aliases:list<string>,artist_aliases:list<string>,album_aliases:list<string>}
     */
    private function normalizeAnomalyResponse(array $payload): array
    {
        return [
            ...$this->normalizeResponse($payload),
            'selected_track_position' => $this->cleanString($payload['selected_track_position'] ?? null),
            'selected_track_title' => $this->cleanString($payload['selected_track_title'] ?? null),
            'title_aliases' => $this->cleanStringList($payload['title_aliases'] ?? []),
            'artist_aliases' => $this->cleanStringList($payload['artist_aliases'] ?? []),
            'album_aliases' => $this->cleanStringList($payload['album_aliases'] ?? []),
        ];
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

    /**
     * @param  array<string, mixed>  $input
     */
    private function anomalyPrompt(array $input): string
    {
        return implode("\n", [
            'Return only JSON in this exact shape: {"verdict":"accept","confidence":0.82,"reason":"short reason","selected_track_position":"2","selected_track_title":"source track title","title_aliases":["alias"],"artist_aliases":["alias"],"album_aliases":["alias"]}.',
            'Allowed verdict values: accept, reject, ambiguous.',
            'Use accept only when the fingerprint candidate and source release are likely the same recording/release, and one listed source track plausibly represents the current track under another language, romanization, or import/custom title.',
            'Use reject when the source release or selected track points to a different work.',
            'Use ambiguous when the listed evidence is plausible but insufficient.',
            'Do not invent canonical titles, artists, albums, release details, IDs, or track positions. Select one track from the supplied source.tracklist only.',
            'Canonical/source fields should be original/source values. Current English, romanized, import, or custom names belong in alias arrays.',
            'Evidence JSON:',
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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

    /**
     * @return list<string>
     */
    private function cleanStringList(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $values = [];
        foreach ($value as $item) {
            $clean = $this->cleanString($item);
            if ($clean !== null) {
                $values[$clean] = $clean;
            }
        }

        return array_values($values);
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
