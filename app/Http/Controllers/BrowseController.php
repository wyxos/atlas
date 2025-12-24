<?php

namespace App\Http\Controllers;

use App\Browser;
use Illuminate\Http\JsonResponse;

class BrowseController extends Controller
{
    /**
     * Get a page of browse items from the selected service (CivitAI, Wallhaven, etc.).
     */
    public function index(): JsonResponse
    {
        $payload = Browser::handle();

        // Set index and ensure key is set for each item
        $items = array_map(function ($item, $index) use ($payload) {
            $item['index'] = $index;
            $page = (int) ($payload['filter']['page'] ?? 1);
            $item['page'] = $page;
            // Ensure key is set (combines page and id)
            if (! isset($item['key'])) {
                $item['key'] = "{$page}-{$item['id']}";
            }

            return $item;
        }, $payload['items'], array_keys($payload['items']));

        return response()->json([
            'items' => $items,
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
            $servicesMeta[] = [
                'key' => $serviceInstance::key(),
                'label' => $serviceInstance::label(),
                'defaults' => $serviceInstance->defaultParams(),
            ];
        }

        return response()->json([
            'services' => $servicesMeta,
        ]);
    }
}
