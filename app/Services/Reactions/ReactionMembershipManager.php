<?php

namespace App\Services\Reactions;

use App\Events\PlaylistMembershipChanged;
use App\Models\File;
use App\Models\Playlist;
use Illuminate\Support\Facades\DB;

class ReactionMembershipManager
{
    /**
     * Predict the previous and new playlist IDs for a user's file based on current reaction state.
     * Does not write changes or dispatch events.
     * Returns [prevId, newId].
     */
    public function predictChange(int $userId, int $fileId): array
    {
        [$prevId] = $this->currentReactionPlaylistId($userId, $fileId);
        $targetId = $this->targetReactionPlaylistId($userId, $fileId);

        return [$prevId, $targetId];
    }

    /**
     * Synchronize membership for reaction smart playlists for the given user+file.
     * Detaches from previous reaction playlist and attaches to the new target playlist if needed.
     * Broadcasts PlaylistMembershipChanged when a change occurred.
     * Returns [prevId, newId].
     */
    public function syncForUserFile(int $userId, int $fileId): array
    {
        [$prevId, $prevPlaylist] = $this->currentReactionPlaylistId($userId, $fileId);
        $newId = $this->targetReactionPlaylistId($userId, $fileId);

        if ($prevId === $newId) {
            return [$prevId, $newId];
        }

        // Apply changes
        if ($prevId) {
            DB::table('file_playlist')
                ->where('playlist_id', $prevId)
                ->where('file_id', $fileId)
                ->delete();
        }
        if ($newId) {
            DB::table('file_playlist')->upsert([
                'playlist_id' => $newId,
                'file_id' => $fileId,
            ], ['playlist_id', 'file_id']);
        }

        // Broadcast to user's private channel
        event(new PlaylistMembershipChanged($userId, $fileId, $prevId, $newId));

        return [$prevId, $newId];
    }

    /**
     * Returns [prevId, Playlist|null] for the current reaction playlist membership.
     */
    protected function currentReactionPlaylistId(int $userId, int $fileId): array
    {
        $p = Playlist::query()
            ->where('user_id', $userId)
            ->where('is_smart', true)
            ->whereNotNull('smart_parameters->reaction')
            ->whereExists(function ($q) use ($fileId) {
                $q->from('file_playlist as fp')
                    ->whereColumn('fp.playlist_id', 'playlists.id')
                    ->where('fp.file_id', $fileId);
            })
            ->first();

        return [$p?->id ? (int) $p->id : null, $p];
    }

    /**
     * Determine the target reaction playlist id given current Reaction state.
     * Returns null when no target (e.g., user has no matching smart playlist).
     */
    protected function targetReactionPlaylistId(int $userId, int $fileId): ?int
    {
        $reaction = DB::table('reactions')
            ->where('user_id', $userId)
            ->where('file_id', $fileId)
            ->value('type');

        $targetReaction = $reaction ?: 'unrated';

        $targetId = Playlist::query()
            ->where('user_id', $userId)
            ->where('is_smart', true)
            ->where('smart_parameters->reaction', $targetReaction)
            ->value('id');

        return $targetId ? (int) $targetId : null;
    }
}
