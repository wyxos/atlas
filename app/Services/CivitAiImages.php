<?php

namespace App\Services;

use App\Models\File;
use App\Support\FileTypeDetector;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CivitAiImages extends BaseService
{
    public const string KEY = 'civit-ai-images';

    public const string SOURCE = 'CivitAI';

    public const string LABEL = 'CivitAI Images';

    public const bool HOTLINK_PROTECTED = true;

    /**
     * Fetch images from CivitAI Images API.
     *
     * @throws ConnectionException
     */
    public function fetch(array $params = []): array
    {
        $this->params = $params;

        $base = 'https://civitai.com/api/v1/images';

        $response = Http::acceptJson()
            ->get($base, $this->formatParams());

        // Handle HTTP errors
        if ($response->failed()) {
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

    public function validateDownload(File $file): bool
    {
        if (! $file->downloaded || ! $file->path) {
            return true;
        }

        $metadata = is_array($file->listing_metadata) ? $file->listing_metadata : (is_string($file->listing_metadata) ? json_decode($file->listing_metadata, true) : []);
        $type = $metadata['type'] ?? null;

        if ($type !== 'video') {
            return true;
        }

        $extension = strtolower((string) pathinfo($file->path, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $file->mime_type);

        return ($extension === 'mp4' && str_contains($mimeType, 'video/mp4'))
            || ($extension === 'webm' && str_contains($mimeType, 'video/webm'));
    }

    public function fixDownload(File $file): bool
    {
        if (! $file->downloaded || ! $file->path) {
            return false;
        }

        $metadata = is_array($file->listing_metadata) ? $file->listing_metadata : (is_string($file->listing_metadata) ? json_decode($file->listing_metadata, true) : []);
        $type = $metadata['type'] ?? null;

        if ($type !== 'video') {
            return false;
        }

        $extension = strtolower((string) pathinfo($file->path, PATHINFO_EXTENSION));
        $mimeType = strtolower((string) $file->mime_type);

        // Detect actual MIME type from file if database MIME is wrong
        $actualMimeType = $mimeType;
        if (in_array($extension, ['bin', 'webp'], true) || ! str_contains($mimeType, 'video/')) {
            $storage = \Illuminate\Support\Facades\Storage::disk('atlas_app');
            if ($storage->exists($file->path)) {
                $fullPath = $storage->path($file->path);
                if (function_exists('finfo_open') && is_file($fullPath)) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    if ($finfo !== false) {
                        $detectedMime = finfo_file($finfo, $fullPath);
                        finfo_close($finfo);
                        if ($detectedMime && str_starts_with(strtolower($detectedMime), 'video/')) {
                            $actualMimeType = strtolower($detectedMime);
                        }
                    }
                }
            }
        }

        // Skip if already correct
        if (($extension === 'mp4' && str_contains($actualMimeType, 'video/mp4'))
            || ($extension === 'webm' && str_contains($actualMimeType, 'video/webm'))) {
            return false;
        }

        $resolver = new UrlResolver($file->id);
        $realUrl = $resolver->resolve();

        if (! $realUrl) {
            return false;
        }

        try {
            $response = Http::timeout(300)->get($realUrl);

            if (! $response->successful()) {
                return false;
            }

            $content = $response->body();
            if (empty($content)) {
                return false;
            }

            $baseFilename = pathinfo($file->filename, PATHINFO_FILENAME);
            $isWebm = str_contains($realUrl, '.webm');
            $newExt = $isWebm ? 'webm' : 'mp4';
            $newMimeType = $isWebm ? 'video/webm' : 'video/mp4';
            $newFilename = $baseFilename.'.'.$newExt;
            $newPath = \App\Support\PartitionedPathHelper::generatePath($newFilename);

            // Ensure subdirectory exists
            $subdir = \App\Support\PartitionedPathHelper::getSubdirectory($newFilename);
            $subdirPath = "downloads/{$subdir}";
            $storage = \Illuminate\Support\Facades\Storage::disk('atlas_app');
            if (! $storage->exists($subdirPath)) {
                $storage->makeDirectory($subdirPath);
            }

            $storage->put($newPath, $content);

            if ($file->path && $file->path !== $newPath) {
                \Illuminate\Support\Facades\Storage::disk('atlas_app')->delete($file->path);
            }

            $file->update([
                'path' => $newPath,
                'filename' => $newFilename,
                'ext' => $newExt,
                'mime_type' => $newMimeType,
                'url' => $realUrl,
            ]);

            $file->refresh();
            $file->searchable();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function shouldProxyOriginal(File $file): bool
    {
        return true;
    }

    public function proxyOriginal(Request $request, File $file): SymfonyResponse
    {
        $url = (string) $file->url;
        if ($url === '') {
            throw new \RuntimeException('Missing CivitAI asset URL');
        }

        $referrer = $file->referrer_url ?: 'https://civitai.com/';

        return $this->proxyCivitaiAsset($url, $file, $referrer);
    }

    public function proxyThumbnail(Request $request, File $file): ?SymfonyResponse
    {
        $thumbnailUrl = (string) $file->thumbnail_url;
        if ($thumbnailUrl === '') {
            return null;
        }

        $referrer = $file->referrer_url ?: 'https://civitai.com/';

        return $this->proxyCivitaiAsset($thumbnailUrl, $file, $referrer, true);
    }

    protected function proxyCivitaiAsset(string $url, File $file, string $referrer, bool $isThumbnail = false): SymfonyResponse
    {
        $origin = 'https://civitai.com';

        $headers = [
            'Referer' => $referrer,
            'Origin' => $origin,
            'User-Agent' => config('services.civitai.user_agent', 'Atlas/1.0'),
            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ];

        // Add API key as header if available
        $apiKey = config('services.civitai.key');
        if ($apiKey) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $response = Http::withHeaders($headers)->timeout(30)->get($url);

        if (! $response->ok()) {
            throw new \RuntimeException('CivitAI upstream responded with status '.$response->status());
        }

        $contentType = $response->header('Content-Type') ?: ($file->mime_type ?: 'application/octet-stream');
        if ($isThumbnail && str_starts_with((string) $contentType, 'application/') && $file->mime_type) {
            $contentType = $file->mime_type;
        }

        $filename = $file->filename ?: ($isThumbnail ? 'thumbnail.jpg' : 'file');

        return response($response->body(), 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
