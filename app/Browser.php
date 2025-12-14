<?php

namespace App;

use App\Http\Controllers\Concerns\ModeratesFiles;
use App\Services\BaseService;
use App\Services\BrowsePersister;
use App\Services\CivitAiImages;
use App\Services\FileItemFormatter;
use App\Services\Wallhaven;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Storage;

class Browser
{
    use ModeratesFiles;

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
        $source = (string) ($params['source'] ?? CivitAiImages::key());

        // Get available services
        $services = $this->getAvailableServices();
        $servicesMeta = [];
        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);
            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
            ];
        }

        // Resolve service instance
        $serviceClass = $services[$source] ?? $services[CivitAiImages::key()] ?? CivitAiImages::class;
        $service = app($serviceClass);

        if (method_exists($service, 'setParams')) {
            $service->setParams($params);
        }

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

        // Moderation: evaluate prompts and auto-dislike matches using shared trait
        $moderationResult = $this->moderateFiles(collect($persisted));
        $matchedIds = $moderationResult['removedIds'];
        $previewBag = $moderationResult['previewBag'];
        $newlyAutoDislikedCount = $moderationResult['newlyAutoDislikedCount'];

        // Filter out auto-disliked files and update ids array
        $persisted = $moderationResult['filtered']->all();

        // Transform persisted files to items format for frontend
        $page = (int) (request()->input('page', 1));
        $items = FileItemFormatter::format($persisted, $page);

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                ...$filter,
                'page' => request()->input('page', 1),
                'next' => $filter['next'] ?? null,
            ],
            'moderation' => [
                'auto_disliked_count' => (int) $newlyAutoDislikedCount,
                'previews' => array_slice($previewBag, 0, 4),
                'ids' => $matchedIds,
            ],
            'error' => $serviceError,
            'services' => $servicesMeta,
        ];
    }

    /**
     * Get available browse services.
     *
     * @return array<string, class-string<BaseService>>
     */
    protected function getAvailableServices(): array
    {
        return [
            CivitAiImages::key() => CivitAiImages::class,
            Wallhaven::key() => Wallhaven::class,
        ];
    }
}
