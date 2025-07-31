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

    private function getFileExtension(array $itemData): string
    {
        return pathinfo(parse_url($itemData['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    }

    private function getMimeType(array $itemData): string
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
            ]
        ];
    }

}
