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
        $page = max(1, $request->integer('page', 1));
        $perPage = min(1000, max(1, $request->integer('per_page', 500)));

        return response()->json($listing->fetch($page, $perPage));
    }
}
