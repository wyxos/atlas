<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAudioPlaylistCoverRequest;
use App\Models\Playlist;
use App\Services\Audio\AudioCoverResolver;
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

    public function updateCover(
        UpdateAudioPlaylistCoverRequest $request,
        Playlist $playlist,
        AudioCoverResolver $coverResolver,
        AudioPlaylistListingService $listing,
    ): JsonResponse {
        $validated = $request->validated();
        $coverMode = (string) $validated['cover_mode'];

        $playlist->forceFill([
            'cover_file_ids' => $coverMode === 'custom'
                ? $coverResolver->normalizeCoverFileIds($validated['cover_file_ids'] ?? [])
                : null,
            'cover_mode' => $coverMode,
        ])->save();

        return response()->json([
            'playlist' => $listing->format($playlist->refresh()),
        ]);
    }
}
