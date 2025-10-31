<?php

namespace App\Http\Controllers;

use App\Browser;
use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Playlist;
use App\Models\Reaction;
use App\Services\BlacklistService;
use App\Services\CivitAiImages;
use Atlas\Plugin\Contracts\ServiceRegistry;
// from atlas-backup style
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class BrowseController extends Controller
{
    private const CIVIT_AI_POSTS_KEY = 'civit-ai-posts';

    public function reportMissing(Request $request, File $file): JsonResponse
    {
        $shouldVerify = $request->boolean('verify', true);

        if ($shouldVerify && $this->mediaStillAvailable($file)) {
            return response()->json([
                'ok' => true,
                'id' => $file->id,
                'not_found' => false,
                'verified' => true,
            ]);
        }

        // Mark file as not_found to avoid retrying expired/removed remote URLs
        if ($file->not_found !== true) {
            $file->forceFill(['not_found' => true])->saveQuietly();
            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing errors
            }
            // Attach to per-user "Not Found" playlist
            try {
                $userId = optional($request->user())->id;
                if ($userId) {
                    $playlist = Playlist::firstOrCreate(
                        ['user_id' => $userId, 'name' => 'Not Found'],
                        ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['status' => 'not_found']]
                    );
                    $playlist->files()->syncWithoutDetaching([$file->id]);
                    // Update index with playlist_ids for this file
                    $file->loadMissing('playlists');
                    $file->searchable();
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return response()->json([
            'ok' => true,
            'id' => $file->id,
            'not_found' => true,
            'verified' => $shouldVerify,
        ]);
    }

    public function index(ServiceRegistry $registry)
    {
        // Allow long-running upstream calls for posts source
        $source = (string) request('source', CivitAiImages::key());
        $this->configureLongRunningSource($source, $registry);

        $payload = Browser::handle();
        $payload['filter']['source'] = $source;

        return Inertia::render('browse/Index', $payload);
    }

    public function data(ServiceRegistry $registry)
    {
        // Allow long-running upstream calls for posts source
        $source = (string) request('source', CivitAiImages::key());
        $this->configureLongRunningSource($source, $registry);

        $payload = Browser::handle();
        $payload['filter']['source'] = $source;

        return response()->json($payload);
    }

    protected function configureLongRunningSource(string $source, ServiceRegistry $registry): void
    {
        if ($source !== self::CIVIT_AI_POSTS_KEY) {
            return;
        }

        if (! $registry->get(self::CIVIT_AI_POSTS_KEY)) {
            return;
        }

        if (\function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        @ini_set('max_execution_time', '0');
    }

    /**
     * Toggle a reaction on a file for the current user (browse-specific).
     */
    public function react(Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:love,like,dislike,funny',
            'state' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $type = $validated['type'];
        $explicitState = array_key_exists('state', $validated) ? (bool) $validated['state'] : null;

        $current = Reaction::query()
            ->where('file_id', $file->id)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->first();

        $currentType = $current?->type;
        $turnOn = $explicitState !== null ? $explicitState : ($currentType !== $type);

        if ($turnOn) {
            Reaction::updateOrCreate(
                ['file_id' => $file->id, 'user_id' => $user?->id],
                ['type' => $type]
            );
        } else {
            if ($current) {
                $current->delete();
            }
        }

        $final = Reaction::query()
            ->where('file_id', $file->id)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->value('type');

        return response()->json([
            'id' => $file->id,
            'loved' => $final === 'love',
            'liked' => $final === 'like',
            'disliked' => $final === 'dislike',
            'funny' => $final === 'funny',
        ]);
    }

    /**
     * Dislike + blacklist for browsing; also delete local file from atlas_app if present.
     */
    public function dislikeBlacklist(File $file): JsonResponse
    {
        // Centralized blacklist + auto-dislike for current user
        (new BlacklistService)->apply([$file->id], 'disliked');

        return response()->json(['ok' => true]);
    }

    public function unblacklist(File $file): JsonResponse
    {
        $file->forceFill([
            'blacklisted_at' => null,
            'blacklist_reason' => null,
        ])->saveQuietly();

        // Ensure search index reflects new flags immediately
        try {
            $file->searchable();
        } catch (\Throwable $e) {
            // ignore
        }

        // Remove dislike reaction for current user if present
        $userId = optional(request()->user())->id;
        if ($userId) {
            Reaction::query()
                ->where('file_id', $file->id)
                ->where('user_id', $userId)
                ->where('type', 'dislike')
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function batchUnblacklist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file_ids' => ['required', 'array'],
            'file_ids.*' => ['integer', 'exists:files,id'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['file_ids'])));
        if (! $ids) {
            return response()->json(['ok' => true, 'updated' => 0]);
        }

        DB::table('files')
            ->whereIn('id', $ids)
            ->update(['blacklisted_at' => null, 'blacklist_reason' => null, 'updated_at' => now()]);

        $userId = optional($request->user())->id;
        if ($userId) {
            Reaction::query()
                ->whereIn('file_id', $ids)
                ->where('user_id', $userId)
                ->where('type', 'dislike')
                ->delete();
        }

        return response()->json(['ok' => true, 'updated' => count($ids)]);
    }

    public function previewSeen(File $file): JsonResponse
    {
        // Set preview timestamp only once (first time)
        if ($file->previewed_at === null) {
            $now = now();

            $file->update(['previewed_at' => $now]);
        }

        // Increment previewed count
        $file->increment('previewed_count');

        $file->searchable();

        if ($file->previewed_count >= 3) {
            if ($file->path) {
                return response()->json([
                    'ok' => true,
                    'id' => $file->id,
                    'previewed_count' => (int) $file->previewed_count,
                    'previewed_at' => optional($file->previewed_at)->toIso8601String(),
                    'blacklisted' => (bool) $file->blacklisted_at,
                ]);
            }

            if ($file->blacklisted_at && $file->reactions()->where('type', 'dislike')->where('user_id', request()->user()->id)->exists()) {
                return response()->json([
                    'ok' => true,
                    'id' => $file->id,
                    'previewed_count' => (int) $file->previewed_count,
                    'previewed_at' => optional($file->previewed_at)->toIso8601String(),
                    'blacklisted' => (bool) $file->blacklisted_at,
                ]);
            }

            if ($file->downloaded) {
                return response()->json([
                    'ok' => true,
                    'id' => $file->id,
                    'previewed_count' => (int) $file->previewed_count,
                    'previewed_at' => optional($file->previewed_at)->toIso8601String(),
                    'blacklisted' => (bool) $file->blacklisted_at,
                ]);
            }

            if ($file->download()->exists()) {
                return response()->json([
                    'ok' => true,
                    'id' => $file->id,
                    'previewed_count' => (int) $file->previewed_count,
                    'previewed_at' => optional($file->previewed_at)->toIso8601String(),
                    'blacklisted' => (bool) $file->blacklisted_at,
                ]);
            }

            (new BlacklistService)->apply([$file->id], 'auto:previewed_threshold');
        }

        return response()->json([
            'ok' => true,
            'id' => $file->id,
            'previewed_count' => (int) $file->previewed_count,
            'previewed_at' => optional($file->previewed_at)->toIso8601String(),
            'blacklisted' => (bool) $file->blacklisted_at,
        ]);
    }

    public function fileSeen(File $file): JsonResponse
    {
        $now = now();
        // Set full-view timestamp only once (first time)
        if (! $file->seen_at) {
            $file->forceFill(['seen_at' => $now])->saveQuietly();
        }

        // Increment seen count (full-size)
        $file->increment('seen_count');

        // If no preview was recorded before this moment (count == 0), bump preview to 1
        // and set previewed_at if it's still null.
        if ((int) $file->previewed_count === 0) {
            if ($file->previewed_at === null) {
                $file->forceFill(['previewed_at' => $now])->saveQuietly();
            }
            $file->increment('previewed_count');

            $file->searchable();
        }

        return response()->json([
            'ok' => true,
            'id' => $file->id,
            'seen_count' => (int) $file->seen_count,
            'seen_at' => optional($file->seen_at)->toIso8601String(),
        ]);
    }

    /**
     * Combined reaction + download trigger in one request.
     * type: love | like | funny
     */
    public function reactDownload(Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:love,like,funny',
        ]);

        $user = $request->user();
        $type = $validated['type'];

        // If file is blacklisted and user is applying a positive reaction, clear blacklist flags first
        if ($file->blacklisted_at !== null) {
            $file->forceFill([
                'blacklisted_at' => null,
                'blacklist_reason' => null,
            ])->saveQuietly();
            try {
                $file->searchable();
            } catch (\Throwable $e) {
                // ignore indexing errors
            }
        }

        // Persist reaction (ON)
        Reaction::updateOrCreate(
            ['file_id' => $file->id, 'user_id' => $user?->id],
            ['type' => $type]
        );

        // Re-index file to reflect updated reaction arrays in Typesense
        try {
            $file->searchable();
        } catch (\Throwable $e) {
            // ignore indexing errors
        }

        // Trigger download pipeline similar to atlas-backup
        try {
            DownloadFile::dispatch($file);
        } catch (\Throwable $e) {
            // ignore dispatch errors; client will still get ack
        }

        return response()->json([
            'ok' => true,
            'id' => $file->id,
            'reaction' => $type,
        ]);
    }

    public function batchReact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'numeric',
            'type' => 'required|string|in:love,like,funny,dislike',
        ]);

        $ids = collect($validated['ids'])
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();

        if (empty($ids)) {
            return response()->json(['ids' => []]);
        }

        $type = $validated['type'];
        $userId = optional($request->user())->id;

        if ($type === 'dislike') {
            // Centralized blacklist + dislike write
            (new BlacklistService)->apply($ids, 'container:batch');
        } else {
            if ($userId) {
                $now = now();
                $rows = array_map(fn ($fid) => [
                    'file_id' => $fid,
                    'user_id' => $userId,
                    'type' => $type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $ids);

                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('reactions')->upsert(
                        $chunk,
                        ['user_id', 'file_id'],
                        ['type', 'updated_at']
                    );
                }
            }
        }

        // For non-dislike reactions, enqueue download if not downloaded yet
        if ($type !== 'dislike') {
            $toDownload = File::query()
                ->whereIn('id', $ids)
                ->where(function ($q) {
                    $q->where('downloaded', false)
                        ->orWhereNull('downloaded')
                        ->orWhereNull('path');
                })
                ->where(function ($q) {
                    $q->whereNull('not_found')->orWhere('not_found', false);
                })
                ->get();

            foreach ($toDownload as $file) {
                try {
                    \App\Jobs\DownloadFile::dispatch($file);
                } catch (\Throwable $e) {
                    // ignore dispatch errors; continue with others
                }
            }
        }

        return response()->json(['ids' => $ids, 'type' => $type]);
    }

    protected function mediaStillAvailable(File $file): bool
    {
        $thumbnailPath = $file->thumbnail_path ? ltrim($file->thumbnail_path, '/') : null;
        if ($thumbnailPath && Storage::disk('atlas_app')->exists($thumbnailPath)) {
            return true;
        }

        $originalPath = $file->path ? ltrim($file->path, '/') : null;
        if ($originalPath && Storage::disk('atlas')->exists($originalPath)) {
            return true;
        }

        $candidateUrl = $file->thumbnail_url ?? $file->url;
        if ($candidateUrl) {
            try {
                $response = Http::timeout(3)->withoutVerifying()->head($candidateUrl);
                if ($response->successful()) {
                    return true;
                }
            } catch (\Throwable $e) {
                // ignore network issues and fall back to marking missing
            }
        }

        return false;
    }
}
