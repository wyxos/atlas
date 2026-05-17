<?php

namespace App\Services;

use App\Support\FileTypeDetector;
use App\Support\HttpRateLimiter;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Wallhaven extends BaseService
{
    public const string KEY = 'wallhaven';

    public const string SOURCE = 'Wallhaven';

    public const string LABEL = 'Wallhaven';

    public const bool HOTLINK_PROTECTED = true;

    /**
     * Fetch images from Wallhaven API.
     *
     * @throws ConnectionException
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $base = 'https://wallhaven.cc/api/v1/search';

        // Throttle requests to Wallhaven domain
        HttpRateLimiter::throttleDomain('wallhaven.cc', 10, 60);

        $headers = [
            'Referer' => 'https://wallhaven.cc/',
            'User-Agent' => config('services.wallhaven.user_agent', 'Atlas/1.0 (+https://wallhaven.cc)'),
        ];

        // Make request with retry logic for 429 errors
        $response = HttpRateLimiter::requestWithRetry(
            fn () => Http::withHeaders($headers)->acceptJson(),
            $base,
            $this->formatParams(),
            maxRetries: 3,
            baseDelaySeconds: 2
        );

        // Handle HTTP errors
        if ($response->failed()) {
            // Log 429 errors for debugging
            if ($response->status() === 429) {
                Log::warning('Wallhaven API rate limited', [
                    'status' => 429,
                    'retry_after' => $response->header('Retry-After'),
                    'url' => $base,
                ]);
            }

            // Return empty structure that transform() can handle
            return [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 24,
                    'total' => 0,
                ],
            ];
        }

        $json = $response->json();

        // Handle null or invalid JSON responses
        if (! is_array($json)) {
            return [
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 24,
                    'total' => 0,
                ],
            ];
        }

        return $json;
    }

    public function formatParams(): array
    {
        return app(WallhavenQueryParameters::class)->format($this->params);
    }

    public function defaultParams(): array
    {
        return app(WallhavenQueryParameters::class)->defaults();
    }

    public function filterSchema(): array
    {
        return app(WallhavenQueryParameters::class)->schema();
    }

    /**
     * Return a normalized structure with files and next cursor.
     */
    public function transform(array $response, array $params = []): array
    {
        [$rows, $next] = $this->transformResponse($response);

        $mapped = array_map(fn ($row) => $this->transformRow((array) $row), $rows);

        $totalRaw = $response['meta']['total'] ?? null;
        $total = is_numeric($totalRaw) ? (int) $totalRaw : null;

        return [
            'files' => $mapped,
            'filter' => [
                ...$this->params,
                'next' => $next,
            ],
            'meta' => [
                'total' => $total,
            ],
        ];
    }

    /**
     * Extract rows and next cursor/url from the raw API response.
     */
    protected function transformResponse(array $response): array
    {
        $rows = $response['data'] ?? [];

        $meta = $response['meta'] ?? [];
        $currentPage = (int) ($meta['current_page'] ?? 1);
        $lastPage = (int) ($meta['last_page'] ?? 1);

        // Generate next page cursor if not on last page
        $next = null;
        if ($currentPage < $lastPage) {
            $next = (string) ($currentPage + 1);
        }

        return [$rows, $next];
    }

    protected function transformRow(array $row): array
    {
        $now = Carbon::now();
        $id = $row['id'] ?? '';
        $path = $row['path'] ?? '';
        $thumbs = $row['thumbs'] ?? [];
        $thumbnail = $thumbs['original'] ?? $thumbs['large'] ?? $path;

        $referrer = $row['url'] ?? "https://wallhaven.cc/w/{$id}";

        $file = [
            'source' => 'Wallhaven',
            'source_id' => (string) $id,
            'url' => $path,
            'referrer_url' => $referrer,
            'filename' => Str::random(40),
            'ext' => FileTypeDetector::extensionFromUrl($path),
            'mime_type' => FileTypeDetector::mimeFromUrl($path),
            'hash' => null,
            'size' => isset($row['file_size']) && is_numeric($row['file_size']) && (int) $row['file_size'] > 0
                ? (int) $row['file_size']
                : null,
            'title' => null,
            'description' => null,
            'preview_url' => $thumbnail,
            'listing_metadata' => json_encode($row),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $metadata = [
            'file_referrer_url' => $referrer,
            'payload' => json_encode([
                'width' => $row['dimension_x'] ?? null,
                'height' => $row['dimension_y'] ?? null,
                'resolution' => $row['resolution'] ?? null,
                'ratio' => $row['ratio'] ?? null,
                'file_size' => $row['file_size'] ?? null,
                'file_type' => $row['file_type'] ?? null,
                'purity' => $row['purity'] ?? null,
                'category' => $row['category'] ?? null,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'file' => $file,
            'metadata' => $metadata,
        ];
    }

    public function decorateOriginalUrl(\App\Models\File $file, string $originalUrl, ?\Illuminate\Contracts\Auth\Authenticatable $viewer = null): string
    {
        // Wallhaven requires referer headers for hotlink protection
        return $originalUrl;
    }
}
