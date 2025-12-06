<?php

namespace App;

use App\Services\CivitAiImages;
use Illuminate\Http\Client\ConnectionException;

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

        try {
            $transformed = $service->transform($response, $params);
            $items = $transformed['items'] ?? [];
            $next = $transformed['next'] ?? null;
        } catch (\Throwable $e) {
            // If transform fails, use empty arrays
            $items = [];
            $next = null;
            if (! $serviceError) {
                $serviceError = [
                    'message' => 'Failed to process service response: '.$e->getMessage(),
                    'status' => null,
                    'exception' => get_class($e),
                ];
            }
        }

        return [
            'items' => $items,
            'filter' => [
                ...$service->defaultParams(),
                'page' => request()->input('page', 1),
                'next' => $next, // Return cursor as 'next' in filter (matches atlas pattern)
            ],
            'error' => $serviceError,
        ];
    }
}
