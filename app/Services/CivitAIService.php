<?php

namespace App\Services;

use App\Models\File;
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
            'sort' => 'Newest',
            'period' => 'AllTime',
//            'nsfw' => 'false',
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

        // Check if all items were blacklisted and we have a next page available
        $allItemsBlacklisted = empty($transformedItems) && ! empty($result['items']) && $hasNextPage;

        return [
            'items' => $transformedItems,
            'page' => $currentPage, // Current page value (cursor or null for first page)
            'nextPage' => $nextPage, // Next page value (cursor or null if no more)
            'hasNextPage' => $hasNextPage,
            'allItemsBlacklisted' => $allItemsBlacklisted, // Flag to trigger next page fetch
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
     * Upsert transformed items as File instances based on referrer_url using bulk operations.
     */
    private function upsertFiles(array $transformedItems): array
    {
        if (empty($transformedItems)) {
            return [];
        }

        // Extract referrer URLs and metadata
        $referrerUrls = [];
        $metadataByReferrer = [];

        foreach ($transformedItems as $item) {
            $referrerUrls[] = $item['referrer_url'];
            $metadataByReferrer[$item['referrer_url']] = $item['_metadata'] ?? [];
        }

        // Get existing files by referrer_url
        $existingFiles = File::whereIn('referrer_url', $referrerUrls)
            ->get()
            ->keyBy('referrer_url');

        $filesToCreate = [];
        $filesToUpdate = [];
        $fileIds = [];

        foreach ($transformedItems as $item) {
            $referrerUrl = $item['referrer_url'];
            unset($item['_metadata']); // Remove metadata before file operations

            if ($existingFiles->has($referrerUrl)) {
                // Update existing file
                $existingFile = $existingFiles->get($referrerUrl);
                $existingFile->update($item);
                $fileIds[] = $existingFile->id;
            } else {
                // Prepare for bulk insert
                $item['created_at'] = now();
                $item['updated_at'] = now();
                $filesToCreate[] = $item;
            }
        }

        // Bulk insert new files
        if (! empty($filesToCreate)) {
            File::insert($filesToCreate);

            // Get the newly created files
            $newReferrerUrls = array_column($filesToCreate, 'referrer_url');
            $newFiles = File::whereIn('referrer_url', $newReferrerUrls)
                ->get()
                ->keyBy('referrer_url');

            foreach ($newFiles as $file) {
                $fileIds[] = $file->id;
            }
        }

        // Handle metadata in bulk
        $this->upsertMetadata($fileIds, $metadataByReferrer, $referrerUrls);

        // Return all files with metadata loaded, excluding blacklisted ones
        return File::whereIn('id', $fileIds)
            ->where('is_blacklisted', false)
            ->with('metadata')
            ->get()
            ->all();
    }

    /**
     * Upsert metadata for files in bulk.
     */
    private function upsertMetadata(array $fileIds, array $metadataByReferrer, array $referrerUrls): void
    {
        if (empty($fileIds) || empty($metadataByReferrer)) {
            return;
        }

        // Get file ID to referrer URL mapping
        $fileMapping = File::whereIn('id', $fileIds)
            ->select('id', 'referrer_url')
            ->get()
            ->keyBy('referrer_url');

        // Get existing metadata
        $existingMetadata = File::whereIn('id', $fileIds)
            ->with('metadata')
            ->get()
            ->pluck('metadata', 'id')
            ->filter();

        $metadataToCreate = [];
        $metadataToUpdate = [];

        foreach ($metadataByReferrer as $referrerUrl => $metadata) {
            if (empty($metadata) || ! $fileMapping->has($referrerUrl)) {
                continue;
            }

            $fileId = $fileMapping->get($referrerUrl)->id;

            if ($existingMetadata->has($fileId)) {
                // Update existing metadata
                $existingMetadata->get($fileId)->update(['payload' => $metadata]);
            } else {
                // Prepare for bulk insert
                $metadataToCreate[] = [
                    'file_id' => $fileId,
                    'payload' => json_encode($metadata),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        // Bulk insert new metadata
        if (! empty($metadataToCreate)) {
            DB::table('file_metadata')->insert($metadataToCreate);
        }
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
            ];
        }

        return $uiItems;
    }
}
