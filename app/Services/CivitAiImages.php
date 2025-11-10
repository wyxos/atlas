<?php

namespace App\Services;

use App\Support\FileTypeDetector;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

        return Http::acceptJson()
            ->get($base, $this->formatParams())
            ->json();
    }

    public function formatParams(): array
    {
        $limit = isset($this->params['limit']) ? (int) $this->params['limit'] : 20;
        $limit = max(0, min(200, $limit));
        $cursor = (isset($this->params['page']) && (int) $this->params['page'] > 1) ? (string) $this->params['page'] : null;
        $sort = $this->params['sort'] ?? 'Newest';
        $nsfw = $this->params['nsfw'] ?? null; // boolean or enum: None|Soft|Mature|X
        $type = $this->resolveType($this->params['type'] ?? null);

        $query = [
            'limit' => $limit,
            'cursor' => $cursor,
            'sort' => $sort,
        ];

        if (config('services.civitai.key')) {
            $query[] = config('services.civitai.key');
        }

        if ($nsfw !== null) {
            // Pass through as-is; API accepts boolean or string levels depending on endpoint
            $query['nsfw'] = $nsfw;
        }

        if ($type !== null) {
            $query['type'] = $type;
        }

        $this->params['type'] = $type;

        return $query;
    }

    public function defaultParams(): array
    {
        return [
            'nsfw' => 0,
            'limit' => 20,
            'sort' => 'Newest',
            // Normalize to UI 'sorting' if consumer needs it; Wallhaven service reads 'sort' and maps to 'sorting'.
        ];
    }

    /**
     * Return a normalized structure with files and page.
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
        $rows = $response['items'];

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
        $token = $m[1];
        $guid = $m[2];
        $thumbnail = "https://image.civitai.com/{$token}/{$guid}/anim=false,width=450,optimized=true/{$id}.jpeg";

        if ($row['type'] == 'video') {
            $url = "https://image.civitai.com/{$token}/{$guid}/transcode=true,original=true,quality=90/{$id}.mp4";
            $thumbnail = "https://image.civitai.com/{$token}/{$guid}/transcode=true,width=450,optimized=true/{$id}.mp4";
        }

        $file = [
            'source' => 'CivitAI',
            'source_id' => $id,
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

    /**
     * Containers for Browser mapping.
     * Order matters: post first, then user, then model.
     */
    public function containers(array $listingMetadata = [], array $detailMetadata = []): array
    {
        $username = $listingMetadata['username'] ?? null;
        $postId = $listingMetadata['postId'] ?? null;
        $baseModel = $listingMetadata['baseModel'] ?? null;

        return [
            ['label' => 'post',  'key' => 'postId',    'value' => $postId],
            ['label' => 'user',  'key' => 'username',  'value' => $username],
            ['label' => 'model', 'key' => 'baseModel', 'value' => $baseModel],
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
}
