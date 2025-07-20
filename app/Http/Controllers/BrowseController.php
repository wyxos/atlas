<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class BrowseController extends Controller
{
    private const CIVITAI_API_BASE = 'https://civitai.com/api/v1';
    
    /**
     * Debug endpoint to test CivitAI API directly.
     */
    public function debug(Request $request)
    {
        try {
            $response = Http::timeout(30)
                ->get(self::CIVITAI_API_BASE . '/images', [
                    'page' => 1,
                    'limit' => 5,
                    'sort' => 'Most Reactions',
                    'period' => 'AllTime',
                    'nsfw' => 'false'
                ]);

            if (!$response->successful()) {
                return response()->json([
                    'error' => 'CivitAI API request failed',
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            $data = $response->json();
            $images = $this->transformImagesToImages($data['items'] ?? [], 'debug_page_1');
            
            return response()->json([
                'success' => true,
                'raw_data_count' => count($data['items'] ?? []),
                'transformed_images_count' => count($images),
                'sample_image' => $images[0] ?? null,
                'first_model' => $data['items'][0] ?? null
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Exception occurred',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Display the browse page with initial CivitAI data.
     */
    public function index(Request $request): Response
    {
        $page = (int) $request->get('page', 1);
        $cursor = $request->get('cursor');
        $limit = 20;
        
        $result = $this->fetchCivitAIImages($page, $limit, $cursor);
        
        return Inertia::render('Browse', [
            'initialImages' => $result['images'],
            'currentPage' => $page,
            'hasNextPage' => $result['hasNextPage'],
            'nextCursor' => $result['nextCursor'] ?? null,
        ]);
    }
    
    /**
     * Fetch images from CivitAI API with support for both cursor and page-based pagination.
     */
    private function fetchCivitAIImages(int $page, int $limit, ?string $cursor = null): array
    {
        $params = [
            'limit' => $limit,
            'sort' => 'Most Reactions', 
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
                'meta' => [
                    'model_name' => $imageData['meta']['Model'] ?? null,
                    'model_id' => null,
                    'version_name' => null,
                    'blurhash' => $imageData['hash'] ?? null,
                    'prompt' => $imageData['meta']['prompt'] ?? null,
                    'seed' => $imageData['meta']['seed'] ?? null,
                ]
            ];
        }

        return $transformedImages;
    }

}
