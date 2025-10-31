<?php

namespace App\Http\Controllers;

use App\Events\FileReactionUpdated;
use App\Models\File;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Inertia\Inertia;

// for timestamps

class AudioController extends Controller
{
    /**
     * Stream an audio file by ID (bare minimum implementation).
     */
    public function stream(Request $request, File $file)
    {
        // Only allow streaming local files we have on disk
        $path = $file->path;
        abort_unless($path, 404);

        $disk = \Storage::disk('atlas');
        if (! $disk->exists($path)) {
            abort(404);
        }

        // Content-Type header from DB when available; fallback to a generic audio type
        $mime = $file->mime_type ?: 'audio/mpeg';

        // Range support for seeking
        $size = $disk->size($path);
        $rangeHeader = $request->headers->get('Range');

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
            $start = $matches[1] !== '' ? (int) $matches[1] : 0;
            $end = $matches[2] !== '' ? (int) $matches[2] : ($size - 1);
            $start = max(0, $start);
            $end = min($end, $size - 1);

            if ($start > $end || $start >= $size) {
                // Invalid range
                return response('', 416, [
                    'Content-Range' => "bytes */{$size}",
                    'Accept-Ranges' => 'bytes',
                ]);
            }

            $length = $end - $start + 1;

            $headers = [
                'Content-Type' => $mime,
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
                'Content-Length' => (string) $length,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline; filename="'.$file->filename.'"',
            ];

            return response()->stream(function () use ($disk, $path, $start, $length) {
                $stream = $disk->readStream($path);
                if ($stream === false) {
                    return;
                }
                // Attempt to seek to start
                if ($start > 0) {
                    @fseek($stream, $start);
                }
                $bytesRemaining = $length;
                while ($bytesRemaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(8192, $bytesRemaining));
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                    $bytesRemaining -= strlen($chunk);
                }
                fclose($stream);
            }, 206, $headers);
        }

        // Fallback: full response inline
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
        ];

        return $disk->response($path, $file->filename, $headers, 'inline');
    }

    /**
     * Display a playlist by ID at /playlists/{playlistId}.
     */
    public function playlist(Request $request, Playlist $playlist)
    {
        $search = [];

        // Determine if this is the special Not Found playlist
        $isNotFoundPlaylist = false;
        try {
            $params = is_array($playlist->smart_parameters ?? null) ? $playlist->smart_parameters : [];
            $isNotFoundPlaylist = ($playlist->name === 'Not Found') || ((string) ($params['status'] ?? '') === 'not_found');
        } catch (\Throwable $e) {
        }

        $playlistFileIds = File::query()
            ->join('file_playlist', 'files.id', '=', 'file_playlist.file_id')
            ->where('file_playlist.playlist_id', $playlist->id)
            ->where('files.mime_type', 'like', 'audio/%')
            ->when($isNotFoundPlaylist, fn ($q) => $q->where('files.not_found', true), fn ($q) => $q->where('files.not_found', false))
            ->whereNull('files.blacklisted_at')
            ->pluck('files.id')
            ->toArray();

        if ($query = $request->input('query')) {
            // Fully engine-side filtering via Scout
            $searchResults = File::search($query)
                ->where('mime_group', 'audio')
                ->where('not_found', $isNotFoundPlaylist ? true : false)
                ->where('blacklisted', false)
                ->whereIn('playlist_ids', [(int) $playlist->id])
                ->get();

            $search = $searchResults
                ->map(fn ($file) => ['id' => (int) $file->id])
                ->values()
                ->all();
        }

        $files = array_map(fn ($id) => ['id' => $id], $playlistFileIds);

        $isSpotifyPlaylist = false;
        try {
            $params = is_array($playlist->smart_parameters ?? null) ? $playlist->smart_parameters : [];
            $isSpotifyPlaylist = ($playlist->name === 'Spotify') || (isset($params['source']) && (string) $params['source'] === 'spotify');
        } catch (\Throwable $e) {
        }

        // Determine if this playlist contains any Spotify items (mixed playlists support)
        $containsSpotify = (bool) File::query()
            ->join('file_playlist', 'files.id', '=', 'file_playlist.file_id')
            ->where('file_playlist.playlist_id', $playlist->id)
            ->where(function ($q) {
                $q->where('files.source', 'spotify')
                    ->orWhere('files.mime_type', 'audio/spotify')
                    ->orWhere('files.listing_metadata->source', 'spotify');
            })
            ->exists();

        return Inertia::render('audio/Index', [
            'files' => $files,
            'search' => $search,
            'playlistFileIds' => $playlistFileIds,
            'query' => $request->input('query', ''),
            'playlistId' => $playlist->id,
            'isSpotifyPlaylist' => $isSpotifyPlaylist,
            'containsSpotify' => $containsSpotify,
        ]);
    }

    /**
     * Return audio file IDs for the given playlist (JSON only, no navigation).
     */
    public function playlistIds(Request $request, Playlist $playlist)
    {
        // Determine if this is the special Not Found playlist
        $isNotFoundPlaylist = false;
        try {
            $params = is_array($playlist->smart_parameters ?? null) ? $playlist->smart_parameters : [];
            $isNotFoundPlaylist = ($playlist->name === 'Not Found') || ((string) ($params['status'] ?? '') === 'not_found');
        } catch (\Throwable $e) {
        }

        $ids = File::query()
            ->join('file_playlist', 'files.id', '=', 'file_playlist.file_id')
            ->where('file_playlist.playlist_id', $playlist->id)
            ->where('files.mime_type', 'like', 'audio/%')
            ->when($isNotFoundPlaylist, fn ($q) => $q->where('files.not_found', true), fn ($q) => $q->where('files.not_found', false))
            ->whereNull('files.blacklisted_at')
            ->orderBy('file_playlist.id')
            ->pluck('files.id')
            ->toArray();

        return response()->json(['ids' => array_values($ids)]);
    }

    /**
     * Get details for a specific audio file.
     * This loads full details when an item comes into view.
     */
    public function details($fileId)
    {
        $file = File::with(['metadata', 'covers', 'artists.covers', 'albums.covers'])
            ->find($fileId);

        if (! $file) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $userId = optional(request()->user())->id;
        $type = \App\Models\Reaction::query()
            ->where('file_id', $file->id)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->value('type');

        $payload = $file->toArray();
        $payload['loved'] = $type === 'love';
        $payload['liked'] = $type === 'like';
        $payload['disliked'] = $type === 'dislike';
        $payload['funny'] = $type === 'funny';

        return response()->json($payload);
    }

    /**
     * Get batch details for multiple audio files.
     * Optimized for loading multiple items at once.
     */
    public function batchDetails(Request $request)
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = $request->input('file_ids', []);

        // Load all files with their relationships in a single query
        // Cover model includes URL automatically via accessor
        $files = File::whereIn('id', $fileIds)
            ->with(['metadata', 'covers', 'artists.covers', 'albums.covers'])
            ->get();

        $userId = optional($request->user())->id;
        $reactions = \App\Models\Reaction::query()
            ->whereIn('file_id', $fileIds)
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->pluck('type', 'file_id');

        $data = [];
        foreach ($files as $f) {
            $type = $reactions[$f->id] ?? null;
            $arr = $f->toArray();
            $arr['loved'] = $type === 'love';
            $arr['liked'] = $type === 'like';
            $arr['disliked'] = $type === 'dislike';
            $arr['funny'] = $type === 'funny';
            $data[(string) $f->id] = $arr;
        }

        return response()->json($data);
    }

    /**
     * Toggle a reaction on a file and keep states mutually exclusive.
     * Request payload: { type: 'love'|'like'|'dislike'|'funny', state?: bool }
     * Returns updated reaction fields.
     */
    public function react(Request $request, File $file)
    {
        $validated = $request->validate([
            'type' => 'required|string|in:love,like,dislike,funny',
            'state' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $type = $validated['type'];
        $explicitState = array_key_exists('state', $validated) ? (bool) $validated['state'] : null;

        $current = \App\Models\Reaction::query()
            ->where('file_id', $file->id)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->first();

        $currentType = $current?->type;
        $turnOn = $explicitState !== null ? $explicitState : ($currentType !== $type);

        if ($turnOn) {
            \App\Models\Reaction::updateOrCreate(
                ['file_id' => $file->id, 'user_id' => $user?->id],
                ['type' => $type]
            );
        } else {
            if ($current) {
                $current->delete();
            }
        }

        // Sync reaction smart playlist membership + broadcast change
        try {
            if ($user?->id) {
                $mgr = app(\App\Service\Reactions\ReactionMembershipManager::class);
                $mgr->syncForUserFile((int) $user->id, (int) $file->id);
            }
        } catch (\Throwable $e) {
            // ignore membership sync failures
        }

        // Reindex file so Typesense/Scout reflects new reaction arrays and playlist_ids
        try {
            $file->loadMissing('playlists');
            $file->searchable();
        } catch (\Throwable $e) {
        }

        $final = \App\Models\Reaction::query()
            ->where('file_id', $file->id)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->value('type');

        $state = [
            'id' => $file->id,
            'loved' => $final === 'love',
            'liked' => $final === 'like',
            'disliked' => $final === 'dislike',
            'funny' => $final === 'funny',
        ];

        if ($user?->id) {
            event(new FileReactionUpdated(
                userId: (int) $user->id,
                fileId: (int) $file->id,
                loved: $state['loved'],
                liked: $state['liked'],
                disliked: $state['disliked'],
                funny: $state['funny'],
            ));
        }

        return response()->json($state);
    }

    /**
     * Mark a playlist as the user's active playlist.
     * If no playlist_id is provided, defaults to the current user's "All songs" playlist.
     */
    public function activatePlaylist(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'playlist_id' => 'nullable|integer|exists:playlists,id',
        ]);

        $playlistId = $validated['playlist_id'] ?? null;

        if (! $playlistId) {
            $playlistId = Playlist::query()
                ->where('user_id', $user->id)
                ->where('name', 'All songs')
                ->value('id');
        }

        if (! $playlistId) {
            return response()->json(['error' => 'Playlist not found'], 404);
        }

        $user->forceFill(['active_playlist_id' => (int) $playlistId])->save();

        return response()->json([
            'ok' => true,
            'active_playlist_id' => (int) $user->active_playlist_id,
        ]);
    }
}
