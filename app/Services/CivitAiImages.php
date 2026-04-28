<?php

namespace App\Services;

use App\Support\CivitAiImagesFilterSchema;
use App\Support\CivitAiMediaUrl;
use App\Support\CivitAiPageUrls;
use App\Support\FileTypeDetector;
use App\Support\HttpRateLimiter;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CivitAiImages extends BaseService
{
    public const string KEY = 'civit-ai-images';

    public const string SOURCE = 'CivitAI';

    public const string LABEL = 'CivitAI Images';

    /**
     * Fetch images from CivitAI Images API.
     *
     * @throws ConnectionException
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $base = 'https://civitai.com/api/v1/images';

        $query = $this->formatParams();
        $json = $this->requestJson($base, $query);

        if ($this->shouldRetryModelVersionAsModelId($query, $json)) {
            $candidateId = (int) $query['modelVersionId'];

            if ($this->shouldTreatModelVersionAsModelId($candidateId)) {
                Log::info('CivitAI recovered modelVersionId filter as modelId', [
                    'model_id' => $candidateId,
                ]);

                unset($query['modelVersionId']);
                $query['modelId'] = $candidateId;

                unset($this->params['modelVersionId']);
                $this->params['modelId'] = $candidateId;

                $json = $this->requestJson($base, $query);
            }
        }

        return $json;
    }

    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        $containers = [];

        $postId = isset($listingMetadata['postId']) ? (int) $listingMetadata['postId'] : null;
        if ($postId && $postId > 0) {
            $containers[] = $this->makeContainer('Post', (string) $postId, $listingMetadata);
        }

        $username = isset($listingMetadata['username']) && is_string($listingMetadata['username'])
            ? trim($listingMetadata['username'])
            : null;
        if ($username !== null && $username !== '') {
            $containers[] = $this->makeContainer('User', $username, $listingMetadata);
        }

        $resourceContainers = $listingMetadata['resource_containers'] ?? null;
        if (is_array($resourceContainers)) {
            foreach ($resourceContainers as $resourceContainer) {
                if (! is_array($resourceContainer)) {
                    continue;
                }

                $type = isset($resourceContainer['type']) && is_string($resourceContainer['type'])
                    ? trim($resourceContainer['type'])
                    : null;
                $modelVersionId = isset($resourceContainer['modelVersionId'])
                    ? (int) $resourceContainer['modelVersionId']
                    : null;
                if (! in_array($type, ['Checkpoint', 'LoRA'], true) || ! $modelVersionId || $modelVersionId <= 0) {
                    continue;
                }

                $containers[] = $this->makeContainer($type, (string) $modelVersionId, $listingMetadata, $resourceContainer);
            }
        }

        return $containers;
    }

    public function formatParams(): array
    {
        $limit = isset($this->params['limit']) ? (int) $this->params['limit'] : 20;
        $limit = max(0, min(200, $limit));

        // Vibe contract: `page` is the next token to load.
        // For CivitAI this is a cursor string; `1` means "start" (no cursor).
        $cursor = null;
        $page = $this->params['page'] ?? null;
        if (is_string($page) && $page !== '' && $page !== '1') {
            $cursor = $page;
        }

        $sort = $this->params['sort'] ?? 'Newest';
        $nsfw = $this->resolveNsfw($this->params['nsfw'] ?? null);

        // Preserve UI selection for `type` (including 'all') for persistence/restores,
        // but only send a `type` param upstream when it is a specific filter.
        $rawType = $this->params['type'] ?? null;
        $uiType = null;
        if (is_string($rawType)) {
            $uiType = strtolower(trim($rawType));
            if ($uiType === '') {
                $uiType = null;
            }
        }
        $type = $this->resolveType($uiType);

        $postId = isset($this->params['postId']) ? (int) $this->params['postId'] : null;
        $modelId = isset($this->params['modelId']) ? (int) $this->params['modelId'] : null;
        $modelVersionId = isset($this->params['modelVersionId']) ? (int) $this->params['modelVersionId'] : null;
        $username = isset($this->params['username']) && is_string($this->params['username'])
            ? trim($this->params['username'])
            : null;
        $period = $this->params['period'] ?? null;

        $query = [
            'limit' => $limit,
            'cursor' => $cursor,
            'sort' => $sort,
        ];

        if (config('services.civitai.key')) {
            $query['key'] = config('services.civitai.key');
        }

        if ($nsfw !== null) {
            $query['nsfw'] = $nsfw;
        }

        if ($type !== null) {
            $query['type'] = $type;
        }

        if ($postId && $postId > 0) {
            $query['postId'] = $postId;
        }

        if ($modelId && $modelId > 0) {
            $query['modelId'] = $modelId;
        }

        if ($modelVersionId && $modelVersionId > 0) {
            $query['modelVersionId'] = $modelVersionId;
        }

        if ($username !== null && $username !== '') {
            $query['username'] = $username;
        }

        if (is_string($period) && $period !== '') {
            $query['period'] = $period;
        }

        $this->params['type'] = $uiType;

        return $query;
    }

    public function defaultParams(): array
    {
        return [
            'limit' => 20,
            'sort' => 'Newest',
            'type' => 'all',
            'period' => 'AllTime',
            // CivitAI supports `nsfw` as a boolean.
            // Default to safe mode (false) unless user opts in.
            'nsfw' => false,
            // Normalize to UI 'sorting' if consumer needs it; Wallhaven service reads 'sort' and maps to 'sorting'.
        ];
    }

    public function filterSchema(): array
    {
        return CivitAiImagesFilterSchema::make();
    }

    /**
     * Return a normalized structure with files and next cursor.
     */
    public function transform(array $response, array $params = []): array
    {
        [$rows, $next] = $this->transformResponse($response);

        $mapped = array_map(fn ($row) => $this->transformRow((array) $row), $rows);

        return [
            'files' => $mapped,
            'filter' => [
                ...$this->params,
                'next' => $next,
            ],
            'meta' => [
                'total' => null,
            ],
        ];
    }

    /**
     * Extract rows and next cursor/url from the raw API response.
     */
    protected function transformResponse(array $response): array
    {
        $rows = $response['items'] ?? [];

        $next = $response['metadata']['nextCursor'] ?? null;

        return [$rows, $next];
    }

    protected function transformRow(array $row): array
    {
        $now = Carbon::now();
        $id = $row['id'];
        $rawUrl = is_string($row['url'] ?? null) ? $row['url'] : '';
        $url = CivitAiMediaUrl::normalizeImageUrl($rawUrl) ?? $rawUrl;
        $referrer = CivitAiPageUrls::imageReferrer((string) $id, $row);

        // Build thumbnail from scratch based on token/guid and row id (CivitAI new URL scheme)
        $path = (string) parse_url($url, PHP_URL_PATH);
        preg_match('#^/([^/]+)/([^/]+)/#', $path, $m);
        $token = $m[1] ?? '';
        $guid = $m[2] ?? '';
        $thumbnail = "https://image.civitai.com/{$token}/{$guid}/anim=false,width=450,optimized=true/{$id}.jpeg";

        if (($row['type'] ?? null) == 'video') {
            $url = "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$id}.mp4";
            $thumbnail = "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$id}.mp4";
        }

        $file = [
            'source' => 'CivitAI',
            'source_id' => (string) $id,
            'url' => $url,
            'referrer_url' => $referrer,
            'filename' => Str::random(40),
            'ext' => FileTypeDetector::extensionFromUrl($url),
            'mime_type' => FileTypeDetector::mimeFromUrl($url),
            'hash' => $row['hash'] ?? null,
            'title' => null,
            'description' => null,
            'preview_url' => $thumbnail,
            'listing_metadata' => json_encode($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $meta = $this->normalizeMetadataPayload($row['meta'] ?? []);
        $metadata = [
            'file_referrer_url' => $referrer,
            'payload' => json_encode(array_merge($meta, [
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
            ])),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'file' => $file,
            'metadata' => $metadata,
        ];
    }

    private function normalizeMetadataPayload(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        $nestedMeta = $meta['meta'] ?? null;
        if (! is_array($nestedMeta)) {
            return $meta;
        }

        unset($meta['meta']);

        return array_merge($nestedMeta, $meta);
    }

    protected function resolveType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['image', 'video'], true) ? $normalized : null;
    }

    protected function resolveNsfw(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function requestJson(string $url, array $query = []): array
    {
        $response = $this->request($url, $query);

        if ($response->failed()) {
            return $this->emptyResponse();
        }

        $json = $response->json();

        return is_array($json) ? $json : $this->emptyResponse();
    }

    private function request(string $url, array $query = []): Response
    {
        HttpRateLimiter::throttleDomain('civitai.com', 10, 60);

        $response = HttpRateLimiter::requestWithRetry(
            fn () => Http::acceptJson(),
            $url,
            $query,
            maxRetries: 3,
            baseDelaySeconds: 2
        );

        if ($response->status() === 429) {
            Log::warning('CivitAI API rate limited', [
                'status' => 429,
                'retry_after' => $response->header('Retry-After'),
                'url' => $url,
            ]);
        }

        return $response;
    }

    private function shouldRetryModelVersionAsModelId(array $query, array $response): bool
    {
        if (isset($query['modelId']) || ! isset($query['modelVersionId'])) {
            return false;
        }

        $items = $response['items'] ?? null;

        return is_array($items) && $items === [];
    }

    private function shouldTreatModelVersionAsModelId(int $candidateId): bool
    {
        if ($candidateId <= 0) {
            return false;
        }

        $modelVersionUrl = "https://civitai.com/api/v1/model-versions/{$candidateId}";
        $modelVersionResponse = $this->request($modelVersionUrl);
        if ($modelVersionResponse->successful()) {
            return false;
        }

        if ($modelVersionResponse->status() !== 404) {
            return false;
        }

        $modelUrl = "https://civitai.com/api/v1/models/{$candidateId}";

        return $this->request($modelUrl)->successful();
    }

    private function emptyResponse(): array
    {
        return [
            'items' => [],
            'metadata' => [
                'nextCursor' => null,
            ],
        ];
    }

    public function getBlacklistableContainerTypes(): array
    {
        return [
            'User',
        ];
    }

    private function makeContainer(string $type, string $sourceId, array $listingMetadata, array $resourceContainer = []): array
    {
        return [
            'type' => $type,
            'source_id' => $sourceId,
            'referrer' => CivitAiPageUrls::containerReferrer($type, $sourceId, $listingMetadata, $resourceContainer),
        ];
    }
}
