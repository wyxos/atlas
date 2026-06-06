<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckAudioPlaylistMembershipRequest;
use App\Http\Requests\UpdateAudioPlaylistCoverRequest;
use App\Models\Playlist;
use App\Services\Audio\AudioCoverResolver;
use App\Services\Playlists\AudioPlaylistListingService;
use App\Services\Playlists\AudioPlaylistQueryService;
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

    public function membership(
        CheckAudioPlaylistMembershipRequest $request,
        SystemPlaylistSyncService $sync,
        AudioPlaylistQueryService $playlistQuery,
    ): JsonResponse {
        $user = $request->user();
        $validated = $request->validated();
        $playlistSlug = trim((string) $validated['playlist']);
        $fileIds = $this->normalizeFileIds($validated['file_ids']);

        $sync->syncForUser($user);

        $playlist = Playlist::query()
            ->where('user_id', $user->id)
            ->where('slug', $playlistSlug)
            ->first();
        $files = $playlist instanceof Playlist
            ? $playlistQuery->membershipsForFileIds($playlist, $fileIds)
            : array_map(
                static fn (int $fileId): array => [
                    'id' => $fileId,
                    'is_member' => false,
                ],
                $fileIds,
            );

        return response()->json([
            'playlist' => $playlistSlug,
            'files' => $files,
        ]);
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

    /**
     * @param  list<int>  $fileIds
     * @return list<int>
     */
    private function normalizeFileIds(array $fileIds): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $fileIds,
        )), static fn (int $id): bool => $id > 0));
    }
}
