<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileMetadata;
use Carbon\Carbon;
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
        $page = $this->request->get('page');
        $limit = (int) $this->request->get('limit', 20);

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
            'sort' => $this->request->get('sort', 'Most Reactions'),
            'period' => $this->request->get('period', 'AllTime'),
            'nsfw' => $this->request->boolean('nsfw', false),
        ];

        // For CivitAI, if page is null (first request), don't send cursor
        // If page has a value, it's a cursor string
        if ($page !== null) {
            $params['cursor'] = $page;
        }
        // Note: CivitAI doesn't use traditional page numbers, only cursors

        $response = Http::get(self::CIVITAI_API_BASE.'/images', $params);

        if (! $response->successful()) {
            throw new \Exception('CivitAI API request failed: '.$response->status());
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
     * Transform the response from fetchItems into the final format for the frontend.
     */
    private function transformResponse(array $result, array $transformedItems, $currentPage): array
    {
        $hasNextPage = ! empty($result['metadata']['nextCursor']);
        $nextPage = $hasNextPage ? $result['metadata']['nextCursor'] : null;

        return [
            'items' => $transformedItems,
            'page' => $currentPage, // Current page value (cursor or null for first page)
            'nextPage' => $nextPage, // Next page value (cursor or null if no more)
            'hasNextPage' => $hasNextPage,
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

            $transformedItems[] = [
                // Core identification
                'source' => 'CivitAI',
                'source_id' => (string) $itemData['id'],
                'url' => $itemData['url'],
                'referrer_url' => "https://civitai.com/images/{$itemData['id']}",

                // File properties
                'filename' => basename(parse_url($itemData['url'], PHP_URL_PATH)) ?: 'civitai_'.$itemData['id'],
                'ext' => pathinfo(parse_url($itemData['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg',
                'mime_type' => 'image/'.(pathinfo(parse_url($itemData['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpeg'),
                'hash' => $itemData['hash'] ?? null,

                // Content metadata
                'title' => $meta['prompt'] ?? null,
                'description' => null,
                'thumbnail_url' => $itemData['url'],

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
                'src' => $file->url,
                'width' => $metadata['width'] ?? null,
                'height' => $metadata['height'] ?? null,
                'page' => $pageIdentifier,
                'index' => $index,
                'loved' => $file->loved,
                'liked' => $file->liked,
                'disliked' => $file->disliked,
                'funny' => $file->funny,
            ];
        }

        return $uiItems;
    }
}
