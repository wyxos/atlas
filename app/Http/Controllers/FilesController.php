<?php

namespace App\Http\Controllers;

use App\Listings\FileListing;
use App\Models\File;
use App\Models\Reaction;
use App\Services\TabFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class FilesController extends Controller
{
    /**
     * Display a listing of the files.
     */
    public function index(FileListing $listing): JsonResponse
    {
        Gate::authorize('viewAny', File::class);

        return response()->json($listing->handle());
    }

    /**
     * Display the specified file.
     */
    public function show(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        // Load metadata relationship for prompt data
        $file->load('metadata');

        return response()->json([
            'file' => new \App\Http\Resources\FileResource($file),
        ]);
    }

    /**
     * Serve the file content.
     */
    public function serve(File $file)
    {
        Gate::authorize('view', $file);

        if (! $file->path) {
            abort(404, 'File not found');
        }

        $fullPath = storage_path('app/'.$file->path);

        if (! file_exists($fullPath)) {
            abort(404, 'File not found');
        }

        return response()->file($fullPath, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
        ]);
    }

    /**
     * Remove the specified file from storage.
     */
    public function destroy(File $file): JsonResponse
    {
        Gate::authorize('delete', $file);

        $file->delete();

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    /**
     * Increment the preview count for a file.
     */
    public function incrementPreview(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $file->increment('previewed_count');
        $file->touch('previewed_at');
        $file->refresh();

        $willAutoDislike = false;

        // Check if we should auto-dislike: previewed_count >= 3, no reactions exist,
        // source is not 'local', file has no path (not on disk), and file is not blacklisted
        if ($file->previewed_count >= 3) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            $isNotLocal = $file->source !== 'local';
            $hasNoPath = empty($file->path);
            $isNotBlacklisted = $file->blacklisted_at === null;

            if (! $hasReactions && $isNotLocal && $hasNoPath && $isNotBlacklisted) {
                // Flag that we will auto-dislike (UI will show countdown)
                $willAutoDislike = true;
            }
        }

        return response()->json([
            'message' => 'Preview count incremented.',
            'previewed_count' => $file->previewed_count,
            'will_auto_dislike' => $willAutoDislike,
        ]);
    }

    /**
     * Batch increment preview counts for multiple files.
     */
    public function batchIncrementPreview(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = $request->input('file_ids');
        $user = Auth::user();

        // Load files and authorize
        $files = File::whereIn('id', $fileIds)->get();
        foreach ($files as $file) {
            Gate::authorize('view', $file);
        }

        // Batch increment preview counts
        File::whereIn('id', $fileIds)->increment('previewed_count');
        File::whereIn('id', $fileIds)->update(['previewed_at' => now()]);

        // Refresh files to get updated counts
        $files->each->refresh();

        // Check for will-auto-dislike candidates (previewed_count >= 3, no reactions, etc.)
        $willAutoDislikeFileIds = [];
        $candidates = $files->filter(function ($file) {
            return $file->previewed_count >= 3
                && $file->source !== 'local'
                && empty($file->path)
                && $file->blacklisted_at === null;
        });

        if ($candidates->isNotEmpty()) {
            $candidateIds = $candidates->pluck('id')->toArray();

            // Batch check which files have reactions (single query instead of N queries)
            $filesWithReactions = Reaction::whereIn('file_id', $candidateIds)
                ->pluck('file_id')
                ->toArray();

            // Filter candidates that don't have reactions
            $willAutoDislikeFileIds = array_diff($candidateIds, $filesWithReactions);
        }

        // Build response with updated counts and will-auto-dislike status
        $results = $files->map(function ($file) use ($willAutoDislikeFileIds) {
            return [
                'id' => $file->id,
                'previewed_count' => $file->previewed_count,
                'will_auto_dislike' => in_array($file->id, $willAutoDislikeFileIds),
            ];
        });

        return response()->json([
            'message' => 'Preview counts incremented.',
            'results' => $results,
        ]);
    }

    /**
     * Perform auto-dislike on multiple files (called after countdown expires).
     */
    public function batchPerformAutoDislike(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = $request->input('file_ids');
        $user = Auth::user();

        // Load files and authorize
        $files = File::whereIn('id', $fileIds)->get();
        foreach ($files as $file) {
            Gate::authorize('view', $file);
        }

        // Filter files that still meet conditions
        // Files can be auto-disliked via:
        // 1. Moderation rules (flagged at load time)
        // 2. Preview count >= 3 (flagged after previewing)
        // We don't require preview count here since moderation-flagged files
        // may not have been previewed 3 times yet
        $validFileIds = [];
        foreach ($files as $file) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            $isNotLocal = $file->source !== 'local';
            $hasNoPath = empty($file->path);
            $isNotBlacklisted = $file->blacklisted_at === null;
            $isNotAlreadyAutoDisliked = ! $file->auto_disliked;

            if (! $hasReactions && $isNotLocal && $hasNoPath && $isNotBlacklisted && $isNotAlreadyAutoDisliked) {
                $validFileIds[] = $file->id;
            }
        }

        if (empty($validFileIds)) {
            return response()->json([
                'message' => 'No files meet auto-dislike conditions.',
                'auto_disliked_count' => 0,
                'file_ids' => [],
            ]);
        }

        // Batch update files with auto_disliked = true
        File::whereIn('id', $validFileIds)->update(['auto_disliked' => true]);

        // Batch insert dislike reactions
        $reactionsToInsert = array_map(function ($fileId) use ($user) {
            return [
                'file_id' => $fileId,
                'user_id' => $user->id,
                'type' => 'dislike',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $validFileIds);

        // Check for existing reactions to avoid duplicates
        $existingReactions = Reaction::whereIn('file_id', $validFileIds)
            ->where('user_id', $user->id)
            ->pluck('file_id')
            ->toArray();

        $newReactionsToInsert = array_filter($reactionsToInsert, function ($reaction) use ($existingReactions) {
            return ! in_array($reaction['file_id'], $existingReactions);
        });

        if (! empty($newReactionsToInsert)) {
            Reaction::insert($newReactionsToInsert);
        }

        // Detach files from all tabs belonging to this user
        app(TabFileService::class)->detachFilesFromUserTabs($user->id, $validFileIds);

        return response()->json([
            'message' => 'Files auto-disliked successfully.',
            'auto_disliked_count' => count($validFileIds),
            'file_ids' => $validFileIds,
        ]);
    }

    /**
     * Increment the seen count for a file.
     */
    public function incrementSeen(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $file->increment('seen_count');
        $file->touch('seen_at');

        return response()->json([
            'message' => 'Seen count incremented.',
            'seen_count' => $file->seen_count,
        ]);
    }

    /**
     * Delete all files in the database and empty atlas app storage.
     */
    public function deleteAll(): JsonResponse
    {
        $user = Auth::user();

        // Authorize that user can view any files (admin only)
        Gate::authorize('viewAny', File::class);

        // Delete all files in the database
        $deletedCount = File::count();
        File::query()->delete();

        // Empty atlas app storage
        $atlasDisk = Storage::disk('atlas-app');
        $files = $atlasDisk->allFiles();
        foreach ($files as $file) {
            $atlasDisk->delete($file);
        }

        // Also clear private directory (where File model stores files)
        $localDisk = Storage::disk('local');
        if ($localDisk->exists('private')) {
            $localDisk->deleteDirectory('private');
        }

        return response()->json([
            'message' => 'All files deleted successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }
}
