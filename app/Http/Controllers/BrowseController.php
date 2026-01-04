<?php

namespace App\Http\Controllers;

use App\Browser;
use App\Services\LocalService;
use App\Support\ServiceFilterSchema;
use Illuminate\Http\JsonResponse;

class BrowseController extends Controller
{
    /**
     * Get a page of browse items from the selected service (CivitAI, Wallhaven, etc.).
     */
    public function index(): JsonResponse
    {
        $payload = Browser::handle();

        // FileItemFormatter already sets index, page, and key for each item
        return response()->json([
            'items' => $payload['items'],
            'nextPage' => $payload['filter']['next'] ?? null, // Return cursor as nextPage for frontend
            'services' => $payload['services'] ?? [], // Return available services
            'moderation' => $payload['moderation'] ?? [ // Include moderation data
                'toDislike' => [],
                'moderatedOut' => [],
            ],
        ]);
    }

    /**
     * Get available browse services metadata.
     */
    public function services(): JsonResponse
    {
        // Use reflection to access protected method from Browser class
        $browser = new \App\Browser;
        $reflection = new \ReflectionClass($browser);
        $method = $reflection->getMethod('getAvailableServices');
        $method->setAccessible(true);
        $services = $method->invoke($browser);

        $servicesMeta = [];
        foreach ($services as $key => $serviceClass) {
            $serviceInstance = app($serviceClass);

            // Local mode has its own filter schema and should not show up in the online services list.
            if ($serviceInstance instanceof LocalService) {
                continue;
            }

            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
                'schema' => $serviceInstance->filterSchema(),
            ];
        }

        $localSchema = ServiceFilterSchema::make();
        $sourcesWithAll = $this->sourcesWithAll();
        $sourceOptions = array_map(fn (string $source) => [
            'label' => $source === 'all' ? 'All' : $source,
            'value' => $source,
        ], $sourcesWithAll);

        return response()->json([
            'services' => $servicesMeta,
            'local' => [
                'key' => LocalService::key(),
                'label' => LocalService::label(),
                'defaults' => [
                    'limit' => 20,
                    'source' => 'all',
                    // UI default: all reaction types enabled.
                    'reaction' => ['love', 'like', 'dislike', 'funny'],
                    // Tri-state selects.
                    'downloaded' => 'any',
                    'blacklisted' => 'any',
                ],
                'schema' => $localSchema->fields([
                    $localSchema->pageField([
                        'type' => 'hidden',
                        'description' => 'Pagination cursor (managed automatically).',
                    ]),
                    $localSchema->limitField([
                        'type' => 'number',
                    ]),
                    $localSchema->field('source', [
                        'type' => 'select',
                        'description' => 'Filter by file source.',
                        'options' => $sourceOptions,
                        'default' => 'all',
                    ]),
                    $localSchema->field('reaction', [
                        'type' => 'checkbox-group',
                        'description' => 'Filter by your reaction on a file.',
                        'options' => [
                            ['label' => 'Favorite', 'value' => 'love'],
                            ['label' => 'Like', 'value' => 'like'],
                            ['label' => 'Dislike', 'value' => 'dislike'],
                            ['label' => 'Funny', 'value' => 'funny'],
                        ],
                        'default' => ['love', 'like', 'dislike', 'funny'],
                    ]),
                    $localSchema->field('downloaded', [
                        'type' => 'radio',
                        'description' => 'Whether the file is downloaded.',
                        'options' => [
                            ['label' => 'Any', 'value' => 'any'],
                            ['label' => 'Yes', 'value' => 'yes'],
                            ['label' => 'No', 'value' => 'no'],
                        ],
                        'default' => 'any',
                    ]),
                    $localSchema->field('blacklisted', [
                        'type' => 'radio',
                        'description' => 'Whether the file is blacklisted.',
                        'options' => [
                            ['label' => 'Any', 'value' => 'any'],
                            ['label' => 'Yes', 'value' => 'yes'],
                            ['label' => 'No', 'value' => 'no'],
                        ],
                        'default' => 'any',
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * Get available sources for local feed.
     */
    public function sources(): JsonResponse
    {
        $sourcesWithAll = $this->sourcesWithAll();

        return response()->json([
            'sources' => $sourcesWithAll,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sourcesWithAll(): array
    {
        $sources = \App\Models\File::query()
            ->distinct()
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->orderBy('source')
            ->pluck('source')
            ->toArray();

        return array_merge(['all'], $sources);
    }
}
