<?php

namespace App\Http\Controllers;

use App\Listings\FileListing;
use App\Models\BrowseTab;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

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

        $autoDisliked = false;

        // Check if we should auto-dislike: previewed_count >= 3, no reactions exist,
        // source is not 'local', file has no path (not on disk), and file is not blacklisted
        if ($file->previewed_count >= 3) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            $isNotLocal = $file->source !== 'local';
            $hasNoPath = empty($file->path);
            $isNotBlacklisted = $file->blacklisted_at === null;

            if (! $hasReactions && $isNotLocal && $hasNoPath && $isNotBlacklisted) {
                // Auto-dislike the file
                $user = Auth::user();
                $file->update(['auto_disliked' => true]);

                // Create a dislike reaction
                Reaction::updateOrCreate(
                    [
                        'file_id' => $file->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'type' => 'dislike',
                    ]
                );

                // De-associate from all tabs belonging to this user (but keep in masonry)
                $userTabs = BrowseTab::forUser($user->id)->get();
                foreach ($userTabs as $tab) {
                    $tab->files()->detach($file->id);
                }

                $autoDisliked = true;
            }
        }

        return response()->json([
            'message' => 'Preview count incremented.',
            'previewed_count' => $file->previewed_count,
            'auto_disliked' => $autoDisliked,
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
}
