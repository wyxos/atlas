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
            if (!isset($item['key'])) {
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
}
