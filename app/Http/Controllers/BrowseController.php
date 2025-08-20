<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Services\CivitAIService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrowseController extends Controller
{
    /**
     * Display the browse page with initial CivitAI data.
     */
    public function index(Request $request): Response
    {
        $result = [
            'items' => [],
            'filters' => [
                'page' => $request->get('page', 1), // Current page value (cursor or null for first page)
                'nextPage' => null, // Next page value (cursor or null if no more)
                'sort' => $request->get('sort', 'Newest'),
                'period' => $request->get('period', 'AllTime'),
                'limit' => $request->get('limit', 40), // Default to 40 items per page
                'nsfw' => $request->boolean('nsfw', false),
                'autoNext' => $request->boolean('autoNext', false),
                'container' => $request->get('container', 'images'),
            ]
        ];

        return Inertia::render('Browse', $result);
    }

    public function data(Request $request): Response
    {
        $civitAIService = new CivitAIService($request);
        $result = $civitAIService->fetch();

        return Inertia::render('Browse', $result);
    }

    /**
     * Blacklist a file.
     */
    public function blacklist(Request $request, File $file): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:255'
        ]);

        $file->update([
            'is_blacklisted' => true,
            'blacklist_reason' => $request->input('reason')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Item has been blacklisted'
        ], 200);
    }

    /**
     * Queue a file for download.
     */
    public function download(File $file): JsonResponse
    {
        // Queue the download job
        DownloadFile::dispatch($file);

        return response()->json([
            'success' => true,
            'message' => 'File download started'
        ], 200);
    }

    /**
     * Undo the last blacklisted item - unblacklist it, download it, and like it.
     */
    public function undoLastBlacklist(Request $request): JsonResponse
    {
        // Find the most recently blacklisted item
        $lastBlacklistedFile = File::where('is_blacklisted', true)
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$lastBlacklistedFile) {
            return response()->json([
                'success' => false,
                'message' => 'No blacklisted items found'
            ], 404);
        }

        // Undo the blacklist
        $lastBlacklistedFile->update([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
            'liked' => true, // Like the item
            'liked_at' => now()
        ]);

        // Queue the download
        DownloadFile::dispatch($lastBlacklistedFile);

        return response()->json([
            'success' => true,
            'message' => "Undid blacklist for '{$lastBlacklistedFile->url}', liked it, and started download",
            'file' => [
                'id' => $lastBlacklistedFile->id,
                'filename' => $lastBlacklistedFile->filename,
                'title' => $lastBlacklistedFile->title
            ]
        ], 200);
    }

    /**
     * Block a post by disliking and blacklisting all provided files from that post.
     */
    public function blockPost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'postId' => 'required',
            'fileIds' => 'required|array',
            'fileIds.*' => 'integer|exists:files,id',
        ]);

        $fileIds = $data['fileIds'];
        $now = now();

        // Update all matching files: dislike + blacklist
        File::whereIn('id', $fileIds)->update([
            'disliked' => true,
            'disliked_at' => $now,
            'is_blacklisted' => true,
            'blacklist_reason' => 'Blocked post ' . $data['postId'],
        ]);

        File::whereIn('id', $fileIds)->searchable();

        return response()->json([
            'success' => true,
            'removedIds' => $fileIds,
        ]);
    }

    /**
     * Like a post by liking all provided files and enqueueing downloads with a small delay between each.
     */
    public function likePost(Request $request): JsonResponse
    {
        $data = $request->validate([
            'postId' => 'required',
            'fileIds' => 'required|array',
            'fileIds.*' => 'integer|exists:files,id',
            'delayMs' => 'nullable|integer|min:0',
        ]);

        $fileIds = $data['fileIds'];
        $delayMs = $data['delayMs'] ?? 1000; // default 200ms gap
        $now = now();

        $files = File::whereIn('id', $fileIds)->get();

        $index = 0;
        foreach ($files as $file) {
            // Set liked and clear conflicting flags
            $file->liked = true;
            $file->liked_at = $now;
            $file->loved = false;
            $file->loved_at = null;
            $file->disliked = false;
            $file->disliked_at = null;
            $file->funny = false;
            $file->laughed_at = null;
            // Remove blacklist on positive reaction
            $file->is_blacklisted = false;
            $file->blacklist_reason = null;
            $file->save();

            // Dispatch download with per-item delay if not already downloaded
            if (!$file->downloaded) {
                DownloadFile::dispatch($file)->delay(now()->addMilliseconds($index * $delayMs));
                $index++;
            }
        }

        File::whereIn('id', $fileIds)->searchable();

        return response()->json([
            'success' => true,
            'processedIds' => $files->pluck('id'),
            'count' => $files->count(),
        ]);
    }
}
