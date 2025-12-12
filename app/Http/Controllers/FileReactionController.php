<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\BrowseTab;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class FileReactionController extends Controller
{
    /**
     * Toggle a reaction on a file.
     * Only one reaction can be active at a time - setting a new reaction removes the previous one.
     */
    public function store(Request $request, File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,dislike,funny'],
        ]);

        $user = Auth::user();

        // Find existing reaction for this user and file
        $existingReaction = Reaction::where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        // If clicking the same reaction type, remove it (toggle off)
        if ($existingReaction && $existingReaction->type === $validated['type']) {
            $existingReaction->delete();

            return response()->json([
                'message' => 'Reaction removed.',
                'reaction' => null,
            ]);
        }

        // Delete any existing reaction first (only one reaction allowed at a time)
        if ($existingReaction) {
            $existingReaction->delete();
        }

        // Remove auto_disliked flag if user is reacting (like, funny, favorite - not dislike)
        // If user manually dislikes, keep auto_disliked flag
        // Also remove blacklist flags if file was blacklisted and user is reacting positively
        if (in_array($validated['type'], ['love', 'like', 'funny'])) {
            $updates = ['auto_disliked' => false];

            // Clear blacklist if file was blacklisted
            if ($file->blacklisted_at !== null) {
                $updates['blacklisted_at'] = null;
                $updates['blacklist_reason'] = null;
            }

            $file->update($updates);
        }

        // Create the new reaction
        $reaction = Reaction::create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'type' => $validated['type'],
        ]);

        // Dispatch download job if reaction is not dislike
        if ($validated['type'] !== 'dislike') {
            DownloadFile::dispatch($file->id);
        }

        // Detach file from all tabs belonging to this user
        // This removes the file from tabs when a reaction is applied
        $userTabs = BrowseTab::forUser($user->id)->get();
        foreach ($userTabs as $tab) {
            $tab->files()->detach($file->id);
        }

        return response()->json([
            'message' => 'Reaction updated.',
            'reaction' => [
                'type' => $reaction->type,
            ],
        ]);
    }

    /**
     * Get the current user's reaction for a file.
     */
    public function show(File $file): JsonResponse
    {
        Gate::authorize('view', $file);

        $user = Auth::user();

        $reaction = Reaction::where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->first();

        return response()->json([
            'reaction' => $reaction ? [
                'type' => $reaction->type,
            ] : null,
        ]);
    }
}
