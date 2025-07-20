<?php

namespace App\Services;

use Illuminate\Http\Request;
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
        $transformedItems = $this->transformItems($result['items'], $page);

        return $this->transformResponse($result, $transformedItems, $page);
    }

    /**
     * Fetch images from CivitAI API using unified page parameter.
     */
    private function fetchItems($page, int $limit): array
    {
        $params = [
            'limit' => $limit,
            'sort' => 'Newest',
            'period' => 'AllTime',
            'nsfw' => 'false'
        ];

        // For CivitAI, if page is null (first request), don't send cursor
        // If page has a value, it's a cursor string
        if ($page !== null) {
            $params['cursor'] = $page;
        }
        // Note: CivitAI doesn't use traditional page numbers, only cursors

        $response = Http::timeout(30)
            ->get(self::CIVITAI_API_BASE . '/images', $params);

        if (!$response->successful()) {
            throw new \Exception('CivitAI API request failed: ' . $response->status());
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
        $hasNextPage = !empty($result['metadata']['nextCursor']);
        $nextPage = $hasNextPage ? $result['metadata']['nextCursor'] : null;

        return [
            'items' => $transformedItems,
            'page' => $currentPage, // Current page value (cursor or null for first page)
            'nextPage' => $nextPage, // Next page value (cursor or null if no more)
            'hasNextPage' => $hasNextPage,
        ];
    }

    /**
     * Transform CivitAI items data into the format expected by the frontend.
     */
    private function transformItems(array $items, $currentPage): array
    {
        $transformedItems = [];
        $pageIdentifier = $currentPage ?: 'initial';

        foreach ($items as $index => $itemData) {
            $transformedItems[] = [
                'id' => $itemData['id'], // Use actual CivitAI ID
                'src' => $itemData['url'],
                'width' => $itemData['width'],
                'height' => $itemData['height'],
                'page' => "page_{$pageIdentifier}_{$index}",
                'index' => $index,
            ];
        }

        return $transformedItems;
    }
}
