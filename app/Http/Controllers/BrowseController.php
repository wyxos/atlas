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

        // Set index for each item
        $items = array_map(function ($item, $index) use ($payload) {
            $item['index'] = $index;
            $item['page'] = (int) ($payload['filter']['page'] ?? 1);

            return $item;
        }, $payload['items'], array_keys($payload['items']));

        return response()->json([
            'items' => $items,
            'nextPage' => $payload['filter']['next'] ?? null, // Return cursor as nextPage for frontend
            'services' => $payload['services'] ?? [], // Return available services
        ]);
    }
}
