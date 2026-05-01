<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\Reaction;
use App\Services\DownloadedFileResetService;
use App\Services\FileReactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class FileReactionController extends Controller
{
    /**
     * Set a reaction on a file.
     * Only one reaction can be active at a time, and reapplying the same reaction keeps it in place.
     */
    public function store(Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:love,like,funny'],
            'force_download' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();

        $type = $validated['type'];
        $forceDownload = (bool) ($validated['force_download'] ?? false);
        $wasDownloaded = (bool) $file->downloaded;
        $hasDownloadedFile = $wasDownloaded
            && is_string($file->path)
            && $file->path !== ''
            && Storage::disk(config('downloads.disk'))->exists($file->path);
        $hasUrl = is_string($file->url) && $file->url !== '';
        $hadReactionBefore = Reaction::query()
            ->where('user_id', $user->id)
            ->where('file_id', $file->id)
            ->exists();

        if ($forceDownload) {
            app(DownloadedFileResetService::class)->reset($file);
            $file = $file->refresh();
        }

        $result = app(FileReactionService::class)->set($file, $user, $type);

        return response()->json([
            'message' => 'Reaction updated.',
            'reaction' => $result['reaction'],
            'should_prompt_redownload' => ! $forceDownload
                && $hasDownloadedFile
                && $hasUrl
                && $hadReactionBefore,
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
            'reactions.*.type' => 'required|string|in:love,like,funny',
        ]);

        $user = Auth::user();
        $results = [];
        $fileIds = collect($validated['reactions'])
            ->pluck('file_id')
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->values()
            ->all();
        $files = File::query()
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy('id');
        $existingReactions = Reaction::query()
            ->where('user_id', $user->id)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy('file_id');
        $service = app(FileReactionService::class);

        foreach ($validated['reactions'] as $reactionData) {
            $fileId = (int) $reactionData['file_id'];
            /** @var File $file */
            $file = $files->get($fileId) ?? File::findOrFail($fileId);
            $existingReaction = $existingReactions->get($fileId);
            $queueDownload = ! ($existingReaction && $existingReaction->type === $reactionData['type']);
            $result = $service->set($file, $user, $reactionData['type'], [
                'detachFromTabsOnNoop' => true,
                'queueDownload' => $queueDownload,
            ]);

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
