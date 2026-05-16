<?php

namespace App\Http\Controllers;

use App\Services\Playlists\AudioPlaylistListingService;
use App\Services\Playlists\SystemPlaylistSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AudioPlaylistController extends Controller
{
    public function index(
        SystemPlaylistSyncService $sync,
        AudioPlaylistListingService $listing,
    ): JsonResponse {
        $user = Auth::user();
        $sync->syncForUser($user);

        return response()->json($listing->forUser($user));
    }
}
