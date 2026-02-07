<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Services\FileReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FileReactionController extends Controller
{
    /**
     * Toggle a reaction on a file.
     * Only one reaction can be active at a time - setting a new reaction removes the previous one.
     */
    public function store(Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,dislike,funny'],
        ]);

        $user = Auth::user();
        $result = app(FileReactionService::class)->toggle($file, $user, $validated['type']);

        return response()->json([
            'message' => $result['reaction'] ? 'Reaction updated.' : 'Reaction removed.',
            'reaction' => $result['reaction'],
        ]);
    }

    /**
     * Get the current user's reaction for a file.
     */
    public function show(File $file): JsonResponse
    {
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

        $service = app(FileReactionService::class);

        foreach ($validated['reactions'] as $reactionData) {
            $file = File::findOrFail($reactionData['file_id']);
            $result = $service->toggle($file, $user, $reactionData['type']);

            $results[] = [
                'file_id' => $file->id,
                'reaction' => $result['reaction'],
            ];
        }

        return response()->json([
            'message' => 'Reactions updated.',
            'reactions' => $results,
        ]);
    }
}
