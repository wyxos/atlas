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
     * Fetch and transform CivitAI images for the browse page.
     */
    public function fetch(): array
    {
        $page = (int) $this->request->get('page', 1);
        $cursor = $this->request->get('cursor');
        $limit = 20;

        $result = $this->fetchCivitAIImages($page, $limit, $cursor);

        return [
            'initialImages' => $result['images'],
            'currentPage' => $page,
            'hasNextPage' => $result['hasNextPage'],
            'nextCursor' => $result['nextCursor'] ?? null,
        ];
    }

    /**
     * Fetch images from CivitAI API with support for both cursor and page-based pagination.
     */
    private function fetchCivitAIImages(int $page, int $limit, ?string $cursor = null): array
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

        // Create a batch identifier for cursor-based pagination
        $batchId = $cursor ? "cursor_{$cursor}" : "page_{$page}";

        return [
            'images' => $this->transformImagesToImages($data['items'] ?? [], $batchId),
            'hasNextPage' => !empty($metadata['nextCursor']) || !empty($metadata['nextPage']),
            'nextCursor' => $metadata['nextCursor'] ?? null,
            'nextPage' => $metadata['nextPage'] ?? null
        ];
    }

    /**
     * Transform CivitAI images data into the format expected by the frontend.
     */
    private function transformImagesToImages(array $images, string $batchId): array
    {
        $transformedImages = [];

        foreach ($images as $index => $imageData) {
            $transformedImages[] = [
                'id' => "civitai-image-{$imageData['id']}",
                'src' => $imageData['url'],
                'width' => $imageData['width'],
                'height' => $imageData['height'],
                'page' => $batchId,
                'index' => $index,
            ];
        }

        return $transformedImages;
    }
}
