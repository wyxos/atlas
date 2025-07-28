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
                'page' => null, // Current page value (cursor or null for first page)
                'nextPage' => null, // Next page value (cursor or null if no more)
                'sort' => $request->get('sort', 'Most Reactions'),
                'period' => $request->get('period', 'AllTime'),
                'nsfw' => $request->boolean('nsfw', false),
                'autoNext' => $request->boolean('autoNext', false),
            ]
        ];

        if($request->has('search')){
            $civitAIService = new CivitAIService($request);
            $result = $civitAIService->fetch();
        }

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
}
