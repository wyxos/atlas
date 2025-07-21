<?php

namespace App\Http\Controllers;

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
}
