<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Services\CivitAIService;
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
        $civitAIService = new CivitAIService($request);
        $result = $civitAIService->fetch();

        // Add current filter values to the response
        $result['filters'] = [
            'sort' => $request->get('sort', 'Most Reactions'),
            'period' => $request->get('period', 'AllTime'),
            'nsfw' => $request->boolean('nsfw', false),
        ];

        return Inertia::render('Browse', $result);
    }

    /**
     * Blacklist a file.
     */
    public function blacklist(Request $request, File $file): \Illuminate\Http\JsonResponse
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
    public function download(File $file): \Illuminate\Http\JsonResponse
    {
        // Queue the download job
        DownloadFile::dispatch($file);

        return response()->json([
            'success' => true,
            'message' => 'File download started'
        ], 200);
    }
}
