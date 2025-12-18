<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\BrowseTab;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        // Once a file is reacted to, it should be removed from all tabs for that user
        $userTabIds = BrowseTab::forUser($user->id)->pluck('id');
        if ($userTabIds->isNotEmpty()) {
            DB::table('browse_tab_file')
                ->whereIn('browse_tab_id', $userTabIds)
                ->where('file_id', $file->id)
                ->delete();
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

    /**
     * Batch get reactions for multiple files.
     */
    public function batchShow(Request $request): JsonResponse
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

        // Fetch all reactions for these files and this user
        $reactions = Reaction::where('user_id', $user->id)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy('file_id');

        // Build response with reactions for each file
        $results = collect($fileIds)->map(function ($fileId) use ($reactions) {
            $reaction = $reactions->get($fileId);

            return [
                'file_id' => $fileId,
                'reaction' => $reaction ? [
                    'type' => $reaction->type,
                ] : null,
            ];
        });

        return response()->json([
            'reactions' => $results,
        ]);
    }

    /**
     * Batch store reactions for multiple files.
     */
    public function batchStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reactions' => 'required|array',
            'reactions.*.file_id' => 'required|integer|exists:files,id',
            'reactions.*.type' => 'required|string|in:love,like,dislike,funny',
        ]);

        $user = Auth::user();
        $results = [];

        foreach ($validated['reactions'] as $reactionData) {
            $file = File::findOrFail($reactionData['file_id']);
            Gate::authorize('view', $file);

            // Find existing reaction for this user and file
            $existingReaction = Reaction::where('user_id', $user->id)
                ->where('file_id', $file->id)
                ->first();

            // If clicking the same reaction type, remove it (toggle off)
            if ($existingReaction && $existingReaction->type === $reactionData['type']) {
                $existingReaction->delete();
                $results[] = [
                    'file_id' => $file->id,
                    'reaction' => null,
                ];

                continue;
            }

            // Delete any existing reaction first (only one reaction allowed at a time)
            if ($existingReaction) {
                $existingReaction->delete();
            }

            // Remove auto_disliked flag if user is reacting (like, funny, love - not dislike)
            // Also remove blacklist flags if file was blacklisted and user is reacting positively
            if (in_array($reactionData['type'], ['love', 'like', 'funny'])) {
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
                'type' => $reactionData['type'],
            ]);

            // Dispatch download job if reaction is not dislike
            if ($reactionData['type'] !== 'dislike') {
                DownloadFile::dispatch($file->id);
            }

            // Detach file from all tabs belonging to this user
            // Once a file is reacted to, it should be removed from all tabs for that user
            $userTabIds = BrowseTab::forUser($user->id)->pluck('id');
            if ($userTabIds->isNotEmpty()) {
                DB::table('browse_tab_file')
                    ->whereIn('browse_tab_id', $userTabIds)
                    ->where('file_id', $file->id)
                    ->delete();
            }

            $results[] = [
                'file_id' => $file->id,
                'reaction' => [
                    'type' => $reaction->type,
                ],
            ];
        }

        return response()->json([
            'message' => 'Reactions updated.',
            'reactions' => $results,
        ]);
    }
}
