<?php

namespace App\Services;

use App\Support\FileTypeDetector;
use App\Support\HttpRateLimiter;
use App\Support\ServiceFilterSchema;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
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

        // Throttle requests to CivitAI domain
        HttpRateLimiter::throttleDomain('civitai.com', 10, 60);

        // Make request with retry logic for 429 errors
        $response = HttpRateLimiter::requestWithRetry(
            fn () => Http::acceptJson(),
            $base,
            $this->formatParams(),
            maxRetries: 3,
            baseDelaySeconds: 2
        );

        // Handle HTTP errors
        if ($response->failed()) {
            // Log 429 errors for debugging
            if ($response->status() === 429) {
                Log::warning('CivitAI API rate limited', [
                    'status' => 429,
                    'retry_after' => $response->header('Retry-After'),
                    'url' => $base,
                ]);
            }

            // Return empty structure that transform() can handle
            return [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        }

        $json = $response->json();

        // Handle null or invalid JSON responses
        if (! is_array($json)) {
            return [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        }

        return $json;
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
        $schema = ServiceFilterSchema::make()
            ->keys($this->schemaKeyMap())
            ->types($this->schemaTypeMap())
            ->labels($this->schemaLabelMap());

        return $schema->fields([
            // Global keys (canonical UI keys)
            ...$schema->paginationFields(),

            // Service-specific keys
            $schema->field('postId', [
                'placeholder' => 'The ID of a post to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('modelId', [
                'placeholder' => 'The ID of a model to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('modelVersionId', [
                'placeholder' => 'The ID of a model version to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('username', [
                'placeholder' => 'Filter to images from a specific user (e.g. someUser).',
            ]),
            $schema->field('nsfw', [
                'description' => 'Include NSFW results.',
            ]),
            $schema->field('type', [
                'description' => 'Filter by media type.',
                'options' => [
                    ['label' => 'All', 'value' => 'all'],
                    ['label' => 'Image', 'value' => 'image'],
                    ['label' => 'Video', 'value' => 'video'],
                ],
            ]),
            $schema->field('sort', [
                'description' => 'Order of results.',
                'options' => [
                    ['label' => 'Newest', 'value' => 'Newest'],
                    ['label' => 'Most Reactions', 'value' => 'Most Reactions'],
                    ['label' => 'Most Comments', 'value' => 'Most Comments'],
                ],
            ]),
            $schema->field('period', [
                'description' => 'Time window for sorting.',
                'options' => [
                    ['label' => 'All Time', 'value' => 'AllTime'],
                    ['label' => 'Year', 'value' => 'Year'],
                    ['label' => 'Month', 'value' => 'Month'],
                    ['label' => 'Week', 'value' => 'Week'],
                    ['label' => 'Day', 'value' => 'Day'],
                ],
            ]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function schemaKeyMap(): array
    {
        return [
            'page' => 'cursor',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function schemaTypeMap(): array
    {
        return [
            'page' => 'hidden',
            'limit' => 'number',
            'postId' => 'number',
            'modelId' => 'number',
            'modelVersionId' => 'number',
            'username' => 'text',
            'nsfw' => 'boolean',
            'type' => 'radio',
            'sort' => 'select',
            'period' => 'select',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function schemaLabelMap(): array
    {
        return [
            'postId' => 'Post ID',
            'modelId' => 'Model ID',
            'modelVersionId' => 'Model Version ID',
            'nsfw' => 'NSFW',
        ];
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
        $url = $row['url']; // Keep original URL as-is
        $referrer = "https://civitai.com/images/{$id}";

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
            'thumbnail_url' => $thumbnail,
            'listing_metadata' => json_encode($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $meta = $row['meta'] ?? [];
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

    public function getBlacklistableContainerTypes(): array
    {
        return [
            'User',
        ];
    }
}
