<?php

namespace App;

use App\Models\File;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

class Browser
{
    /**
     * Resolve a source key and return the service transform result.
     *
     * Request inputs:
     * - source: service key (default: 'civit-ai-images')
     * - page: page number for pagination
     * - any other inputs are forwarded to fetch()
     */
    public static function handle(): array
    {
        return (new self)->run();
    }

    /**
     * @throws ConnectionException
     */
    public function run(): array
    {
        $params = request()->all();
        $source = (string) ($params['source'] ?? CivitAiImages::KEY);

        // For now, we only support CivitAI Images
        $service = new CivitAiImages;

        $serviceError = null;
        $response = null;

        try {
            $response = $service->fetch($params);

            // Check if response is empty or invalid (service might be down)
            if (! is_array($response) || empty($response)) {
                $serviceError = [
                    'message' => 'Service returned an empty response',
                    'status' => null,
                ];
                $response = [
                    'items' => [],
                    'metadata' => [
                        'nextCursor' => null,
                    ],
                ];
            }
        } catch (ConnectionException $e) {
            $serviceError = [
                'message' => 'Unable to connect to service',
                'status' => null,
                'exception' => $e->getMessage(),
            ];
            $response = [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        } catch (\Throwable $e) {
            $serviceError = [
                'message' => 'Service error: '.$e->getMessage(),
                'status' => method_exists($e, 'getCode') ? $e->getCode() : null,
                'exception' => get_class($e),
            ];
            $response = [
                'items' => [],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ];
        }

        Storage::disk('local')->put('temp/'.time().'-response.json', json_encode($response, JSON_PRETTY_PRINT));

        try {
            $transformed = $service->transform($response, $params);
            $filesPayload = $transformed['files'] ?? [];
            $filter = $transformed['filter'] ?? [];
        } catch (\Throwable $e) {
            // If transform fails, use empty arrays
            $filesPayload = [];
            $filter = $params;
            if (! $serviceError) {
                $serviceError = [
                    'message' => 'Failed to process service response: '.$e->getMessage(),
                    'status' => null,
                    'exception' => get_class($e),
                ];
            }
        }

        $persisted = app(BrowsePersister::class)->persist($filesPayload);

        // Transform persisted files to items format for frontend
        $items = collect($persisted)->map(function (File $file) {
            $metadata = $file->metadata?->payload ?? [];
            $listingMetadata = $file->listing_metadata ?? [];

            return [
                'id' => (string) ($listingMetadata['id'] ?? $file->source_id ?? $file->id),
                'width' => (int) ($metadata['width'] ?? 500),
                'height' => (int) ($metadata['height'] ?? 500),
                'src' => $file->thumbnail_url ?? $file->url, // Use thumbnail for masonry grid, fallback to original
                'originalUrl' => $file->url, // Keep original URL for full-size viewing
                'thumbnail' => $file->thumbnail_url,
                'type' => str_starts_with($file->mime_type ?? '', 'video/') ? 'video' : 'image',
                'page' => (int) (request()->input('page', 1)),
                'index' => 0, // Will be set by controller
                'notFound' => false,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ],
            'error' => $serviceError,
        ];
    }
}
