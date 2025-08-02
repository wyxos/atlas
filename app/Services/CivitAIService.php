<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileMetadata;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CivitAIService
{
    private const CIVITAI_API_BASE = 'https://civitai.com/api/v1';

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Fetch and transform CivitAI items for the browse page.
     */
    public function fetch(): array
    {
        $container = $this->request->get('container', 'images');

        switch ($container) {
            case 'users':
                return $this->fetchUsers();
            case 'models':
                return $this->fetchModels();
            case 'posts':
                return $this->fetchPosts();
            case 'images':
            default:
                return $this->fetchImages();
        }
    }

    /**
     * Fetch users from CivitAI API.
     */
    public function fetchUsers(): array
    {
        $page = $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 40);
        $query = $this->request->get('query');

        $params = [
            'limit' => min($limit, 200), // CivitAI allows max 200 for creators
            'page' => $page,
        ];

        if ($query) {
            $params['query'] = $query;
        }

        $response = Http::get(self::CIVITAI_API_BASE . '/creators', $params);

        if (!$response->successful()) {
            throw new Exception('CivitAI API request failed: ' . $response->status());
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $metadata = $data['metadata'] ?? [];

        // Transform creators data for UI
        $transformedItems = collect($items)->map(function ($creator, $index) use ($page) {
            return [
                'id' => 'creator_' . $creator['username'], // Use username as unique ID
                'src' => null, // No image for creators
                'original' => null,
                'width' => null,
                'height' => null,
                'page' => $page,
                'index' => $index,
                'username' => $creator['username'],
                'modelCount' => $creator['modelCount'] ?? 0,
                'link' => $creator['link'] ?? null,
                'type' => 'user',
            ];
        })->toArray();

        return [
            'items' => $transformedItems,
            'filters' => [
                'page' => (int) $page,
                'nextPage' => isset($metadata['nextPage']) ? $page + 1 : null,
                'sort' => $this->request->get('sort', 'Newest'),
                'period' => $this->request->get('period', 'AllTime'),
                'limit' => $limit,
                'nsfw' => $this->request->boolean('nsfw', false),
                'autoNext' => $this->request->boolean('autoNext', false),
                'container' => 'users',
            ]
        ];
    }

    /**
     * Fetch models from CivitAI API.
     */
    public function fetchModels(): array
    {
        $page = $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 40);
        $query = $this->request->get('query');
        $sort = $this->request->get('sort', 'Newest');
        $period = $this->request->get('period', 'AllTime');
        $nsfw = $this->request->boolean('nsfw', false);

        $params = [
            'limit' => min($limit, 100), // CivitAI allows max 100 for models
            'page' => $page,
            'nsfw' => $nsfw,
        ];

        // Map our sort options to CivitAI's model sort options
        $sortMapping = [
            'Most Reactions' => 'Highest Rated',
            'Most Comments' => 'Most Downloaded',
            'Newest' => 'Newest',
        ];

        if (isset($sortMapping[$sort])) {
            $params['sort'] = $sortMapping[$sort];
        }

        if ($period !== 'AllTime') {
            $params['period'] = $period;
        }

        if ($query) {
            $params['query'] = $query;
        }

        $response = Http::get(self::CIVITAI_API_BASE . '/models', $params);

        if (!$response->successful()) {
            throw new Exception('CivitAI API request failed: ' . $response->status());
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $metadata = $data['metadata'] ?? [];

        // Transform models data for UI
        $transformedItems = collect($items)->map(function ($model, $index) use ($page) {
            // Get the first image from the first model version if available
            $firstImage = null;
            if (!empty($model['modelVersions']) && !empty($model['modelVersions'][0]['images'])) {
                $firstImage = $model['modelVersions'][0]['images'][0];
            }

            return [
                'id' => 'model_' . $model['id'],
                'src' => $firstImage ? $firstImage['url'] : null,
                'original' => $firstImage ? $firstImage['url'] : null,
                'width' => $firstImage['width'] ?? null,
                'height' => $firstImage['height'] ?? null,
                'page' => $page,
                'index' => $index,
                'modelId' => $model['id'],
                'name' => $model['name'],
                'description' => $model['description'] ?? null,
                'type' => 'model',
                'modelType' => $model['type'] ?? null,
                'nsfw' => $model['nsfw'] ?? false,
                'tags' => $model['tags'] ?? [],
                'creator' => $model['creator'] ?? null,
                'stats' => $model['stats'] ?? null,
                'modelVersions' => $model['modelVersions'] ?? [],
            ];
        })->toArray();

        return [
            'items' => $transformedItems,
            'filters' => [
                'page' => (int) $page,
                'nextPage' => isset($metadata['nextPage']) ? $page + 1 : null,
                'sort' => $sort,
                'period' => $period,
                'limit' => $limit,
                'nsfw' => $nsfw,
                'autoNext' => $this->request->boolean('autoNext', false),
                'container' => 'models',
            ]
        ];
    }

    private function fetchPosts()
    {
        $url = "https://civitai.com/api/trpc/post.getInfinite";

        $queryParams = [
            'input' => json_encode([
                'json' => [
                    'browsingLevel' => 31,
                    'period' => 'Week',
                    'periodMode' => 'published',
                    'sort' => 'Newest',
                    'include' => ['cosmetics'],
                    'excludedTagIds' => [415792, 426772, 5188, 5249, 130818, 130820, 133182, 5351, 306619, 154326, 161829, 163032],
                    'disablePoi' => true,
                    'disableMinor' => true,
                    'cursor' => '2025-07-31T20:33:57.046Z',
                    'authed' => true
                ],
                'meta' => [
                    'values' => [
                        'cursor' => ['Date']
                    ]
                ]
            ])
        ];

        try {
            $response = Http::timeout(30)
                ->get($url, $queryParams);
            if (!$response->successful()) {
                throw new Exception('CivitAI API request failed: ' . $response->status());
            }

            $data = $response->json();

            $items = $data['result']['data']['json']['items'] ?? [];

            $meta = $data['result']['data']['json']['meta'] ?? [];

            $posts = $this->transformPosts($items);

            dd($items[0], $items[0]['images'], $posts);
        }
        catch (Exception $e) {
            throw new Exception('CivitAI API error: ' . $e->getMessage());
        }
    }

    public function transformPosts($data = [])
    {
        // thumbnail url
        // https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/3a3e7dd5-3d10-4168-b1da-9bf7d6467a7e/anim=false,width=450,optimized=true/8.jpeg
        // domain/{get filename from $item[0]['name']}/$item[0]['url']/anim=false,width=450,optimized=true/$item[0]['nsfwLevel'].{get extention from $item[0]['name']}

        // full image url
        // https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/3a3e7dd5-3d10-4168-b1da-9bf7d6467a7e/original=true,quality=90/8.jpeg
        // domain/{get filename from $item[0]['name']}/$item[0]['url']/original=true,quality=90/$item[0]['nsfwLevel'].{get extention from $item[0]['name']}

        // image url
        // https://civitai.com/images/91097383
        // domain/$item[0]['id']

        return collect($data)->map(function($post){

            $image = $post['images'][0];

            // $image['name'] = '8.jpeg';
            $filenameOnly = pathinfo($image['name'], PATHINFO_FILENAME);

            $thumbnail = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$image['url']}/anim=false,width=450,optimized=true/{$filenameOnly}.{$this->getFileExtension($image['name'])}";

            $url = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$image['url']}/original=true,quality=90/{$filenameOnly}.{$this->getFileExtension($image['name'])}";

            $referrer = "https://civitai.com/images/{$image['id']}";

            $postReferrer = "https://civitai.com/posts/{$post['id']}";

            return [
                // Core identification
                'container' => [
                    'source' => 'CivitAI',
                    'source_id' => (string) $post['id'],
                    'referrer' => $postReferrer,
                    '_metadata' => [
                        'data' => $post
                    ]
                ],

                'file' => [
                    'url' => $url,
                    'referrer_url' => $referrer,

                    // File properties
                    'filename' => $image['name'],
                    'ext' => $this->getFileExtension($image['name']),
                    'mime_type' => $this->getMimeType($image['name']),
                    'hash' => $image['hash'] ?? null,

                    // Content metadata
                    'title' => $image['prompt'] ?? null,
                    'description' => null,
                    'thumbnail_url' => $thumbnail,

                    // Metadata for FileMetadata relationship
                    '_metadata' => [
                        'width' => $image['width'] ?? null,
                        'height' => $image['height'] ?? null,
                        'civitai_id' => $image['id'],
                        'civitai_stats' => $image['stats'] ?? null,
                        'data' => $image,
                    ]
                ]
            ];
        })->toArray();
    }

    private function getFileExtension(array|string $itemData): string
    {
        if (is_string($itemData)) {
            // If it's a string, assume it's a filename
            return pathinfo($itemData, PATHINFO_EXTENSION) ?: 'jpg';
        }

        return pathinfo(parse_url($itemData['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    }

    private function getMimeType(array|string $itemData): string
    {
        $extension = strtolower($this->getFileExtension($itemData));

        switch ($extension) {
            case 'jpeg':
            case 'jpg':
                return 'image/jpeg';
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'mp4':
                return 'video/mp4';
            case 'avi':
                return 'video/x-msvideo';
            case 'mov':
                return 'video/quicktime';
            // Add more types as needed
            default:
                return 'application/octet-stream';
        }
    }

    /**
     * Fetch and transform CivitAI images for the browse page.
     */
    public function fetchImages(): array
    {
        // Get the unified 'page' parameter - could be cursor or page number
        $page = $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 40);

        $result = $this->fetchItems($page, $limit);
        $transformedItems = $this->transformItems($result['items']);

        // Upsert items as File instances
        $files = $this->upsertFiles($transformedItems);

        // Format for UI display
        $uiItems = $this->formatForUI($files, $page);

        return $this->transformResponse($result, $uiItems, $page);
    }

    /**
     * Fetch images from CivitAI API using unified page parameter.
     * @throws ConnectionException
     */
    private function fetchItems($page, int $limit): array
    {
        $params = [
            'limit' => $limit,
            'sort' => $this->request->get('sort', 'Newest'),
            'period' => $this->request->get('period', 'AllTime'),
            'nsfw' => $this->request->boolean('nsfw', false),
        ];

        // For CivitAI, if page is null (first request), don't send cursor
        // If page has a value, it's a cursor string
        if ($page != 1) {
            $params['cursor'] = $page;
        }

        // Note: CivitAI doesn't use traditional page numbers, only cursors

        $response = Http::get(self::CIVITAI_API_BASE.'/images', $params);

//        if(app()->environment('local')){
//            // log to a file {time}_civitai.json, the params used and the response returned in storage/logs
//            $logData = [
//                'time' => Carbon::now()->toDateTimeString(),
//                'params' => $params,
//                'response' => $response->json(),
//            ];
//
//            file_put_contents(storage_path('logs/'.Carbon::now()->format('Y-m-d_H-i-s').'_civitai.json'), json_encode($logData, JSON_PRETTY_PRINT));
//        }

        if (! $response->successful()) {
            throw new Exception('CivitAI API request failed: '.$response->status());
        }

        $data = $response->json();
        $metadata = $data['metadata'] ?? [];

        return [
            'items' => $data['items'] ?? [],
            'metadata' => $metadata,
            'currentPage' => $page,
        ];
    }

    /**
     * Transform CivitAI items data to align with files table schema.
     */
    private function transformItems(array $items): array
    {
        $transformedItems = [];

        foreach ($items as $itemData) {
            // Extract metadata if available
            $meta = $itemData['meta'] ?? [];

            $thumbnail = $itemData['url'];

            //  replace in string $thumbnail width=xxx with width=450
            $thumbnail = preg_replace('/width=\d+/', 'width=450', $thumbnail);

            $transformedItems[] = [
                // Core identification
                'source' => 'CivitAI',
                'source_id' => (string) $itemData['id'],
                'url' => $itemData['url'],
                'referrer_url' => "https://civitai.com/images/{$itemData['id']}",

                // File properties
                'filename' => basename(parse_url($itemData['url'], PHP_URL_PATH)) ?: 'civitai_'.$itemData['id'],
                'ext' => $this->getFileExtension($itemData),
                'mime_type' => $this->getMimeType($itemData),
                'hash' => $itemData['hash'] ?? null,

                // Content metadata
                'title' => $meta['prompt'] ?? null,
                'description' => null,
                'thumbnail_url' => $thumbnail,

                // Metadata for FileMetadata relationship
                '_metadata' => array_merge($meta, [
                    'width' => $itemData['width'] ?? null,
                    'height' => $itemData['height'] ?? null,
                    'civitai_id' => $itemData['id'],
                    'civitai_stats' => $itemData['stats'] ?? null,
                    'data' => $itemData,
                ]),
            ];
        }

        return $transformedItems;
    }

    /**
     * Upsert transformed items as File instances based on referrer_url using Laravel's upsert().
     */
    private function upsertFiles(array $transformedItems): array
    {
        if (empty($transformedItems)) {
            return [];
        }

        // Prepare data for upsert operation
        $fileData = collect($transformedItems)->map(function ($item) {
            return [
                'source' => $item['source'],
                'source_id' => $item['source_id'],
                'url' => $item['url'],
                'referrer_url' => $item['referrer_url'],
                'filename' => $item['filename'],
                'ext' => $item['ext'],
                'mime_type' => $item['mime_type'],
                'description' => $item['description'],
                'thumbnail_url' => $item['thumbnail_url'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        })->toArray();

        // Single atomic upsert operation
        File::upsert(
            $fileData,
            ['referrer_url'], // Unique identifier column(s)
            ['url', 'filename', 'ext', 'mime_type', 'description', 'thumbnail_url', 'updated_at'] // Columns to update on conflict
        );

        $referrerUrls = collect($transformedItems)->pluck('referrer_url')->toArray();

        // Get all files that were upserted
        $upsertedFiles = File::query()
            ->whereIn('referrer_url', $referrerUrls)
            ->get()
            ->keyBy('referrer_url');

        // Prepare metadata for upsert
        $metadataToUpsert = collect($transformedItems)
            ->filter(function ($item) {
                return isset($item['_metadata']);
            })
            ->map(function ($item) use ($upsertedFiles) {
                $file = $upsertedFiles->get($item['referrer_url']);
                if ($file) {
                    return [
                        'file_id' => $file->id,
                        'payload' => json_encode($item['_metadata']),
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
                return null;
            })
            ->filter()
            ->toArray();

        // Upsert metadata (insert new, update existing)
        if (!empty($metadataToUpsert)) {
            FileMetadata::upsert(
                $metadataToUpsert,
                ['file_id'], // Unique identifier
                ['payload', 'updated_at'] // Columns to update on conflict
            );
        }

        return File::query()
            ->whereIn('referrer_url', $referrerUrls)
            ->whereNull('seen_preview_at')
            ->whereNull('seen_file_at')
            ->where('liked', false)
            ->where('disliked', false)
            ->where('funny', false)
            ->where('downloaded', false)
            ->where('is_blacklisted', false)
            ->get()
            ->all();
    }

    /**
     * Format File instances for UI display.
     */
    private function formatForUI(array $files, $currentPage): array
    {
        $uiItems = [];
        $pageIdentifier = $currentPage ?: null;

        foreach ($files as $index => $file) {
            $metadata = $file->metadata?->payload ?? [];

            $uiItems[] = [
                'id' => $file->id,
                'src' => $file->thumbnail_url,
                'original' => $file->url,
                'width' => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
                'page' => $pageIdentifier,
                'index' => $index,
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
                'seen_preview_at' => $file->seen_preview_at,
                'seen_file_at' => $file->seen_file_at,
            ];
        }

        return $uiItems;
    }

    /**
     * Transform the response from fetchItems into the final format for the frontend.
     */
    private function transformResponse(array $result, array $transformedItems, $currentPage): array
    {
        $hasNextPage = ! empty($result['metadata']['nextCursor']);
        $nextPage = $hasNextPage ? $result['metadata']['nextCursor'] : null;

        return [
            'items' => $transformedItems,
            'filters' => [
                'page' => $currentPage ?? 1, // Current page value (cursor or null for first page)
                'nextPage' => $nextPage, // Next page value (cursor or null if no more)
                'sort' => $this->request->get('sort', 'Most Reactions'),
                'period' => $this->request->get('period', 'AllTime'),
                'limit' => (int) $this->request->get('limit', 40), // Items per page
                'nsfw' => $this->request->boolean('nsfw', false),
                'autoNext' => $this->request->boolean('autoNext', false),
                'container' => $this->request->get('container', 'images'),
            ]
        ];
    }

}
