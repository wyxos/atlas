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

        // Get will_auto_dislike IDs from moderation (if needed)
        $flaggedIds = [];
        // TODO: Add moderation check if needed

        // Format as full items (not minimal)
        $items = FileItemFormatter::format($files, 1, $flaggedIds, false);

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
