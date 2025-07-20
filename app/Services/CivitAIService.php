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
        $page = (int) $this->request->get('page', 1);
        $cursor = $this->request->get('cursor');
        $limit = (int) $this->request->get('limit', 20);

        $result = $this->fetchItems($page, $limit, $cursor);

        return $this->transformResponse($result, $page);
    }

    /**
     * Fetch images from CivitAI API with support for both cursor and page-based pagination.
     */
    private function fetchItems(int $page, int $limit, ?string $cursor = null): array
    {
        $params = [
            'limit' => $limit,
            'sort' => 'Newest',
            'period' => 'AllTime',
            'nsfw' => 'false'
        ];

        // Use cursor if provided, otherwise fall back to page
        if ($cursor) {
            $params['cursor'] = $cursor;
        } else {
            $params['page'] = $page;
        }

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
            'currentCursor' => $cursor,
        ];
    }

    /**
     * Transform the response from fetchItems into the final format for the frontend.
     */
    private function transformResponse(array $result, int $page): array
    {
        return [
            'items' => $this->transformItems($result['items']),
            'currentPage' => $page,
            'hasNextPage' => !empty($result['metadata']['nextCursor']) || !empty($result['metadata']['nextPage']),
            'nextCursor' => $result['metadata']['nextCursor'] ?? null,
            'previousCursor' => $result['currentCursor'], // Track the previous cursor for backward navigation
        ];
    }

    /**
     * Transform CivitAI items data into the format expected by the frontend.
     */
    private function transformItems(array $items): array
    {
        $transformedItems = [];

        foreach ($items as $index => $itemData) {
            $transformedItems[] = [
                'id' => $itemData['id'], // Use actual CivitAI ID
                'src' => $itemData['url'],
                'width' => $itemData['width'],
                'height' => $itemData['height'],
                'index' => $index,
            ];
        }

        return $transformedItems;
    }
}
