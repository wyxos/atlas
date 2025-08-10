<?php

namespace App\Services;

use App\Jobs\FetchPostImages;
use App\Models\Container;
use App\Models\File;
use App\Models\FileMetadata;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @throws Exception
     */
    public function fetch(): array
    {
        $container = $this->request->get('container', 'images');

        switch ($container) {
            case 'posts':
                return $this->fetchPosts();
            case 'images':
            default:
                return $this->fetchFiles();
        }
    }


    /**
     * Fetch posts from CivitAI API with dynamic parameters.
     */
    public function fetchPosts(): array
    {
        // Extend execution time for CivitAI posts since their API can be slow
        $originalTimeLimit = ini_get('max_execution_time');
        set_time_limit(120); // 2 minutes for CivitAI posts

        $startTime = microtime(true);
        $container = 'posts';
        $requestId = uniqid('posts_', true);

        Log::info("[Timeout Tracker] Starting posts fetch", [
            'request_id' => $requestId,
            'container' => $container,
            'start_time' => $startTime,
            'params' => $this->request->all(),
            'original_time_limit' => $originalTimeLimit,
            'extended_time_limit' => 120
        ]);

        try {
            // Get the unified 'page' parameter - could be cursor or page number
            $page = $this->request->get('page', 1);
            $limit = (int)$this->request->get('limit', 40);

            $step1Time = microtime(true);
            Log::info("[Timeout Tracker] Step 1: Fetching post items", [
                'request_id' => $requestId,
                'elapsed' => ($step1Time - $startTime) * 1000 . 'ms'
            ]);

            $result = $this->fetchPostData($page, $limit);

            $step2Time = microtime(true);
            Log::info("[Timeout Tracker] Step 2: Transforming posts for database", [
                'request_id' => $requestId,
                'fetch_duration' => ($step2Time - $step1Time) * 1000 . 'ms',
                'total_elapsed' => ($step2Time - $startTime) * 1000 . 'ms',
                'items_count' => count($result['items'] ?? [])
            ]);

            $transformedItems = $this->transformPostData($result['items']);

            $step3Time = microtime(true);
            Log::info("[Timeout Tracker] Step 3: Upserting post files", [
                'request_id' => $requestId,
                'transform_duration' => ($step3Time - $step2Time) * 1000 . 'ms',
                'total_elapsed' => ($step3Time - $startTime) * 1000 . 'ms',
                'transformed_count' => count($transformedItems)
            ]);

            // Upsert items as File instances with Container relationships
            $files = $this->upsertPostFiles($transformedItems);

            $step4Time = microtime(true);
            Log::info("[Timeout Tracker] Step 4: Formatting posts for UI", [
                'request_id' => $requestId,
                'upsert_duration' => ($step4Time - $step3Time) * 1000 . 'ms',
                'total_elapsed' => ($step4Time - $startTime) * 1000 . 'ms',
                'files_count' => count($files)
            ]);

            // Format for UI display
            $uiItems = $this->formatPostsForUI($files, $page);

            $endTime = microtime(true);
            $totalDuration = ($endTime - $startTime) * 1000;

            Log::info("[Timeout Tracker] Posts fetch completed successfully", [
                'request_id' => $requestId,
                'format_ui_duration' => ($endTime - $step4Time) * 1000 . 'ms',
                'total_duration' => $totalDuration . 'ms',
                'final_ui_items' => count($uiItems)
            ]);

            // Log warning if request took longer than 10 seconds
            if ($totalDuration > 10000) {
                Log::warning("[Timeout Tracker] Slow posts request detected", [
                    'request_id' => $requestId,
                    'total_duration' => $totalDuration . 'ms',
                    'container' => $container,
                    'params' => $this->request->all()
                ]);
            }

            return $this->transformPostsResponse($result, $uiItems, $page);

        } catch (Exception $e) {
            $errorTime = microtime(true);
            $totalDuration = ($errorTime - $startTime) * 1000;

            Log::error("[Timeout Tracker] Posts fetch failed", [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'total_duration' => $totalDuration . 'ms',
                'container' => $container,
                'params' => $this->request->all(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        } finally {
            // Restore original time limit
            if ($originalTimeLimit !== false) {
                set_time_limit((int)$originalTimeLimit);
            }

            Log::debug("[Timeout Tracker] Execution time limit restored", [
                'request_id' => $requestId ?? 'unknown',
                'restored_limit' => $originalTimeLimit
            ]);
        }
    }

    /**
     * Fetch post data from CivitAI API using unified page parameter with retry logic.
     * @throws ConnectionException
     */
    private function fetchPostData($page, int $limit): array
    {
        $startTime = microtime(true);
        $url = "https://civitai.com/api/trpc/post.getInfinite";
        $maxRetries = 3;
        $timeoutSeconds = 25; // Keep under PHP's max_execution_time
        $retryDelay = 2; // seconds

        $queryParams = [
            'input' => json_encode([
                'json' => [
                    'limit' => $limit,
                    'browsingLevel' => 31,
                    'period' => $this->request->get('period', 'AllTime'),
                    'periodMode' => 'published',
                    'sort' => $this->request->get('sort', 'Newest'),
                    'include' => [],
                    'excludedTagIds' => [],
                    'disablePoi' => true,
                    'disableMinor' => true,
                    'cursor' => $page != 1 ? $page : null,
                    'authed' => true
                ],
                'meta' => [
                    'values' => [
                        'cursor' => ['Date']
                    ]
                ]
            ])
        ];

        Log::info("[Timeout Tracker] Making HTTP request to CivitAI", [
            'url' => $url,
            'params_size' => strlen(json_encode($queryParams)),
            'page' => $page,
            'limit' => $limit,
            'timeout' => $timeoutSeconds,
            'max_retries' => $maxRetries
        ]);

        $lastException = null;

        // Retry loop
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $httpStartTime = microtime(true);

                Log::info("[Timeout Tracker] HTTP request attempt", [
                    'attempt' => $attempt,
                    'max_attempts' => $maxRetries
                ]);

                // Use shorter timeout to stay within PHP's execution limit
                $response = Http::timeout($timeoutSeconds)
                    ->connectTimeout(10)
                    ->retry(1, 1000) // Built-in retry for connection issues
                    ->get($url, $queryParams);

                $httpEndTime = microtime(true);
                $httpDuration = ($httpEndTime - $httpStartTime) * 1000;

                Log::info("[Timeout Tracker] HTTP request completed", [
                    'attempt' => $attempt,
                    'http_duration' => $httpDuration . 'ms',
                    'status_code' => $response->status(),
                    'response_size' => strlen($response->body())
                ]);

                // Handle different types of failures
                if ($response->status() === 504 || $response->status() === 502) {
                    throw new Exception("CivitAI gateway timeout (HTTP {$response->status()})");
                }

                if ($response->status() === 503) {
                    throw new Exception("CivitAI service unavailable (HTTP 503)");
                }

                if (!$response->successful()) {
                    Log::error("[Timeout Tracker] CivitAI API request failed", [
                        'attempt' => $attempt,
                        'status_code' => $response->status(),
                        'response_body' => substr($response->body(), 0, 500), // Limit log size
                        'http_duration' => $httpDuration . 'ms'
                    ]);
                    throw new Exception("CivitAI API request failed: HTTP {$response->status()}");
                }

                // Success - parse the response
                $parseStartTime = microtime(true);
                $data = $response->json();
                $parseEndTime = microtime(true);
                $parseDuration = ($parseEndTime - $parseStartTime) * 1000;

                Log::info("[Timeout Tracker] JSON parsing completed", [
                    'attempt' => $attempt,
                    'parse_duration' => $parseDuration . 'ms',
                    'items_count' => count($data['result']['data']['json']['items'] ?? [])
                ]);

                $metadata = $data['result']['data']['json'] ?? [];

                $totalDuration = (microtime(true) - $startTime) * 1000;
                Log::info("[Timeout Tracker] fetchPostData completed successfully", [
                    'total_duration' => $totalDuration . 'ms',
                    'http_duration' => $httpDuration . 'ms',
                    'parse_duration' => $parseDuration . 'ms',
                    'successful_attempt' => $attempt
                ]);

                return [
                    'items' => $data['result']['data']['json']['items'] ?? [],
                    'metadata' => $metadata,
                    'currentPage' => $page,
                ];

            } catch (Exception $e) {
                $lastException = $e;
                $errorDuration = (microtime(true) - $httpStartTime) * 1000;

                Log::warning("[Timeout Tracker] HTTP request attempt failed", [
                    'attempt' => $attempt,
                    'max_attempts' => $maxRetries,
                    'error' => $e->getMessage(),
                    'error_duration' => $errorDuration . 'ms',
                    'will_retry' => $attempt < $maxRetries
                ]);

                // If this was the last attempt, don't sleep
                if ($attempt < $maxRetries) {
                    Log::info("[Timeout Tracker] Waiting before retry", [
                        'retry_delay' => $retryDelay . 's',
                        'next_attempt' => $attempt + 1
                    ]);
                    sleep($retryDelay);
                    $retryDelay *= 2; // Exponential backoff
                }
            }
        }

        // All attempts failed
        $totalDuration = (microtime(true) - $startTime) * 1000;
        Log::error("[Timeout Tracker] All HTTP request attempts failed", [
            'total_attempts' => $maxRetries,
            'total_duration' => $totalDuration . 'ms',
            'final_error' => $lastException->getMessage()
        ]);

        throw new Exception("CivitAI API request failed after {$maxRetries} attempts: " . $lastException->getMessage());
    }

    /**
     * Transform CivitAI post data to align with files and containers.
     */
    private function transformPostData(array $items): array
    {
        $now = Carbon::now();
        return collect($items)->map(function ($post) use ($now) {
            $image = $post['images'][0];
            $filenameOnly = pathinfo($image['name'], PATHINFO_FILENAME);

            $thumbnail = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$image['url']}/anim=false,width=450,optimized=true/{$filenameOnly}.{$this->getFileExtension($image['name'])}";
            $url = "https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/{$image['url']}/original=true,quality=90/{$filenameOnly}.{$this->getFileExtension($image['name'])}";
            $referrer = "https://civitai.com/images/{$image['id']}";

            $containerData = [
                'type' => 'post',
                'source' => 'CivitAI',
                'source_id' => (string)$post['id'],
                'referrer' => "https://civitai.com/posts/{$post['id']}",
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $fileData = [
                'source' => 'CivitAI',
                'source_id' => (string)$image['id'],
                'url' => $url,
                'referrer_url' => $referrer,
                'filename' => $image['name'],
                'ext' => $this->getFileExtension($image['name']),
                'mime_type' => $this->getMimeType($image['name']),
                'hash' => $image['hash'] ?? null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => $thumbnail,
                'listing_metadata' => json_encode($image),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $metaData = [
                'file_referrer_url' => $referrer,
                'payload' => json_encode([
                    'width' => $image['width'] ?? null,
                    'height' => $image['height'] ?? null,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            return [
                'fileData' => $fileData,
                'metaData' => $metaData,
                'containerData' => $containerData,
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
     * Upsert transformed post items as File instances with related containers.
     */
    private function upsertPostFiles(array $transformedItems): array
    {
        if (empty($transformedItems)) {
            return [];
        }

        // 1) Upsert files
        $fileRows = collect($transformedItems)->map(fn($i) => $i['fileData'])->toArray();
        File::upsert(
            $fileRows,
            ['referrer_url'],
            ['url','filename','ext','mime_type','description','thumbnail_url','listing_metadata','updated_at']
        );

        // 2) Upsert containers
        $containerRows = collect($transformedItems)->map(fn($i) => $i['containerData'])->toArray();
        Container::upsert(
            $containerRows,
            ['source_id','source'],
            ['type','referrer','updated_at']
        );

        // 3) Build maps
        $referrers = collect($transformedItems)->map(fn($i) => $i['fileData']['referrer_url'])->toArray();
        $containers = collect($transformedItems)->map(fn($i) => $i['containerData']['source_id'])->toArray();

        $fileMap = File::whereIn('referrer_url', $referrers)->get()->keyBy('referrer_url');
        $containerMap = Container::where('source','CivitAI')->whereIn('source_id', $containers)->get()->keyBy('source_id');

        // 4) Pivot rows
        $pivotRows = collect($transformedItems)->map(function ($i) use ($fileMap, $containerMap) {
            $file = $fileMap->get($i['fileData']['referrer_url']);
            $container = $containerMap->get($i['containerData']['source_id']);
            if (!$file || !$container) return null;
            return [
                'file_id' => $file->id,
                'container_id' => $container->id,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];
        })->filter()->toArray();

        if (!empty($pivotRows)) {
            DB::table('container_file')->upsert($pivotRows, ['file_id','container_id'], ['updated_at']);
        }

        // 5) FileMetadata rows
        $metaRows = collect($transformedItems)->map(function ($i) use ($fileMap) {
            $meta = $i['metaData'];
            $file = $fileMap->get($meta['file_referrer_url'] ?? $i['fileData']['referrer_url']);
            if (!$file) return null;
            return [
                'file_id' => $file->id,
                'payload' => $meta['payload'],
                'created_at' => $meta['created_at'],
                'updated_at' => $meta['updated_at'],
            ];
        })->filter()->toArray();

        if (!empty($metaRows)) {
            FileMetadata::upsert($metaRows, ['file_id'], ['payload','updated_at']);
        }

        $allFiles = File::with('metadata')->whereIn('referrer_url', $referrers)->get();

        // Dispatch jobs (unchanged)
        $delay = 0;
        foreach ($allFiles as $file) {
            if (!$file->is_blacklisted) {
                FetchPostImages::dispatch($file)->delay(now()->addSeconds($delay));
                $delay += 5;
            }
        }

        return $allFiles->filter(function ($file) {
            return $file->seen_preview_at === null &&
                $file->seen_file_at === null &&
                $file->liked === false &&
                $file->disliked === false &&
                $file->funny === false &&
                $file->downloaded === false &&
                $file->is_blacklisted === false;
        })->values()->all();
    }

    /**
     * Format File instances for UI display with metadata.
     */
    private function formatPostsForUI(array $files, $currentPage): array
    {
        $uiItems = [];
        $pageIdentifier = $currentPage ?: null;

        foreach ($files as $index => $file) {
            // Decode listing metadata
            $listing = $file->listing_metadata ?? [];
            if (is_string($listing)) {
                $listing = json_decode($listing, true) ?? [];
            }
            // Current metadata from FileMetadata payload
            $meta = $file->metadata?->payload ?? [];

            $uiItems[] = [
                'id' => $file->id,
                'src' => $file->thumbnail_url,
                'original' => $file->url,
                // Width/height sourced from current metadata
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'page' => $pageIdentifier,
                'index' => $index,
                // Provide both payloads to the UI
                'metadata' => $meta,
                'listingMetadata' => $listing,
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
     * Transform the response from fetchPostItems into the final format for the frontend.
     */
    private function transformPostsResponse(array $result, array $transformedItems, $currentPage): array
    {
        $hasNextPage = !empty($result['metadata']['nextCursor']);
        $nextPage = $hasNextPage ? $result['metadata']['nextCursor'] : null;

        return [
            'items' => $transformedItems,
            'filters' => [
                'page' => $currentPage ?? 1, // Current page value (cursor or null for first page)
                'nextPage' => $nextPage, // Next page value (cursor or null if no more)
                'sort' => $this->request->get('sort', 'Newest'),
                'period' => $this->request->get('period', 'AllTime'),
                'limit' => (int)$this->request->get('limit', 40), // Items per page
                'nsfw' => $this->request->boolean('nsfw', false),
                'autoNext' => $this->request->boolean('autoNext', false),
                'container' => $this->request->get('container', 'posts'),
            ]
        ];
    }

    /**
     * Fetch and transform CivitAI files (images/videos) for the browse page.
     */
    public function fetchFiles(): array
    {
        // Get the unified 'page' parameter - could be cursor or page number
        $page = $this->request->get('page', 1);
        $limit = (int)$this->request->get('limit', 40);

        $result = $this->fetchFileData($page, $limit);
        $transformedItems = $this->transformFileData($result['items']);

        // Upsert items as File instances
        $files = $this->upsertFiles($transformedItems);

        // Format for UI display
        $uiItems = $this->formatFilesForUI($files, $page);

        return $this->transformFilesResponse($result, $uiItems, $page);
    }

    /**
     * Fetch file data from CivitAI API using unified page parameter.
     * @throws ConnectionException
     */
    private function fetchFileData($page, int $limit): array
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

        $response = Http::get(self::CIVITAI_API_BASE . '/images', $params);

        if(app()->environment('local')){
            // log to a file {time}_civitai.json, the params used and the response returned in storage/logs
            $logData = [
                'time' => Carbon::now()->toDateTimeString(),
                'params' => $params,
                'response' => $response->json(),
            ];

            file_put_contents(storage_path('logs/'.Carbon::now()->format('Y-m-d_H-i-s').'_civitai.json'), json_encode($logData, JSON_PRETTY_PRINT));
        }

        if (!$response->successful()) {
            throw new Exception('CivitAI API request failed: ' . $response->status());
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
     * Transform CivitAI file data to align with files table schema.
     */
    private function transformFileData(array $items): array
    {
        $transformed = [];
        $now = Carbon::now();

        foreach ($items as $itemData) {
            $meta = $itemData['meta'] ?? [];

            $thumbnail = preg_replace('/width=\d+/', 'width=450', $itemData['url']);
            $referrer = "https://civitai.com/images/{$itemData['id']}";

            $fileData = [
                'source' => 'CivitAI',
                'source_id' => (string)$itemData['id'],
                'url' => $itemData['url'],
                'referrer_url' => $referrer,
                'filename' => basename(parse_url($itemData['url'], PHP_URL_PATH)) ?: 'civitai_' . $itemData['id'],
                'ext' => $this->getFileExtension($itemData),
                'mime_type' => $this->getMimeType($itemData),
                'hash' => $itemData['hash'] ?? null,
                'title' => null,
                'description' => null,
                'thumbnail_url' => $thumbnail,
                // Listing payload stored as JSON string ready for DB
                'listing_metadata' => json_encode($itemData),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $metaData = [
                'file_referrer_url' => $referrer, // temporary join key
                'payload' => json_encode(array_merge($meta, [
                    'width' => $itemData['width'] ?? null,
                    'height' => $itemData['height'] ?? null,
                ])),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $transformed[] = [
                'fileData' => $fileData,
                'metaData' => $metaData,
            ];
        }

        return $transformed;
    }

    /**
     * Upsert transformed items as File instances based on referrer_url using Laravel's upsert().
     */
    private function upsertFiles(array $transformedItems): array
    {
        if (empty($transformedItems)) {
            return [];
        }

        // 1) Upsert files with pre-shaped rows
        $fileRows = collect($transformedItems)->map(fn($i) => $i['fileData'])->toArray();
        File::upsert(
            $fileRows,
            ['referrer_url'],
            ['url','filename','ext','mime_type','description','thumbnail_url','listing_metadata','updated_at']
        );

        // 2) Map referrer_url -> file_id
        $referrers = collect($transformedItems)->map(fn($i) => $i['fileData']['referrer_url'])->toArray();
        $fileMap = File::whereIn('referrer_url', $referrers)->get()->keyBy('referrer_url');

        // 3) Upsert metadata rows
        $metaRows = collect($transformedItems)
            ->map(function ($i) use ($fileMap) {
                $meta = $i['metaData'];
                $file = $fileMap->get($meta['file_referrer_url'] ?? $i['fileData']['referrer_url']);
                if (!$file) return null;
                return [
                    'file_id' => $file->id,
                    'payload' => $meta['payload'],
                    'created_at' => $meta['created_at'],
                    'updated_at' => $meta['updated_at'],
                ];
            })
            ->filter()
            ->toArray();

        if (!empty($metaRows)) {
            FileMetadata::upsert($metaRows, ['file_id'], ['payload','updated_at']);
        }

        $allFiles = File::with('metadata')->whereIn('referrer_url', $referrers)->get();

        return $allFiles->filter(function ($file) {
            return $file->seen_preview_at === null &&
                $file->seen_file_at === null &&
                $file->liked === false &&
                $file->disliked === false &&
                $file->funny === false &&
                $file->downloaded === false &&
                $file->is_blacklisted === false;
        })->values()->all();
    }

    /**
     * Format File instances for UI display.
     */
    private function formatFilesForUI(array $files, $currentPage): array
    {
        $uiItems = [];
        $pageIdentifier = $currentPage ?: null;

        foreach ($files as $index => $file) {
            // Decode listing metadata
            $listing = $file->listing_metadata ?? [];
            if (is_string($listing)) {
                $listing = json_decode($listing, true) ?? [];
            }
            // Current metadata from FileMetadata payload
            $meta = $file->metadata?->payload ?? [];

            $uiItems[] = [
                'id' => $file->id,
                'src' => $file->thumbnail_url,
                'original' => $file->url,
                // Width/height sourced from current metadata
                'width' => $meta['width'] ?? null,
                'height' => $meta['height'] ?? null,
                'page' => $pageIdentifier,
                'index' => $index,
                // Provide both payloads to the UI
                'metadata' => $meta,
                'listingMetadata' => $listing,
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
     * Transform the response from fetchFileData into the final format for the frontend.
     */
    private function transformFilesResponse(array $result, array $transformedItems, $currentPage): array
    {
        $hasNextPage = !empty($result['metadata']['nextCursor']);
        $nextPage = $hasNextPage ? $result['metadata']['nextCursor'] : null;

        return [
            'items' => $transformedItems,
            'filters' => [
                'page' => $currentPage ?? 1, // Current page value (cursor or null for first page)
                'nextPage' => $nextPage, // Next page value (cursor or null if no more)
                'sort' => $this->request->get('sort', 'Newest'),
                'period' => $this->request->get('period', 'AllTime'),
                'limit' => (int)$this->request->get('limit', 40), // Items per page
                'nsfw' => $this->request->boolean('nsfw', false),
                'autoNext' => $this->request->boolean('autoNext', false),
                'container' => $this->request->get('container', 'images'),
            ]
        ];
    }

}
