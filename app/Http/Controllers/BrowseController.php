<?php

namespace App\Http\Controllers;

use App\Browser;
use App\Models\File;
use App\Services\FileItemFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Load full item data for a batch of IDs (for virtualization).
     * Used to load full data on-demand when items come into viewport.
     */
    public function items(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return response()->json(['items' => []]);
        }

        // Load files with containers
        $files = File::query()
            ->whereIn('id', $ids)
            ->with('containers')
            ->get();

        // Re-run moderation on files to catch files that should now be auto-disliked
        $fileModerationService = app(\App\Services\FileModerationService::class);
        $moderationResult = $fileModerationService->moderate($files);
        $processedIds = $moderationResult['processedIds'];

        // Filter out files that were just auto-disliked or blacklisted by moderation
        // Also filter out files that are already marked as auto-disliked or blacklisted
        $files = $files->reject(function ($file) use ($processedIds) {
            return in_array($file->id, $processedIds, true)
                || $file->auto_disliked
                || $file->blacklisted_at !== null;
        });

        // Get will_auto_dislike IDs from moderation (if needed)
        $flaggedIds = [];
        // TODO: Add moderation check if needed

        // Format items
        $items = FileItemFormatter::format($files, 1, $flaggedIds);

        // Return items keyed by ID for easy lookup
        $itemsById = [];
        foreach ($items as $item) {
            $itemsById[$item['id']] = $item;
        }

        return response()->json([
            'items' => $itemsById,
        ]);
    }
}
