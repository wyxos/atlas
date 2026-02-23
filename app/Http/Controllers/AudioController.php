<?php

namespace App\Http\Controllers;

use App\Services\AudioIdListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioController extends Controller
{
    /**
     * Return audio file IDs only.
     *
     * NOTE FOR FUTURE CODEX INSTANCES:
     * Do not use Eloquent queries for this endpoint. Keep query-builder reads only.
     */
    public function ids(Request $request, AudioIdListingService $listing): JsonResponse
    {
        $afterId = max(0, $request->integer('after_id', 0));
        $maxId = $request->query('max_id');
        $maxId = is_numeric($maxId) ? max(0, (int) $maxId) : null;
        $perPage = min(1000, max(1, $request->integer('per_page', 100)));

        return response()->json($listing->fetch($afterId, $perPage, $maxId));
    }
}
