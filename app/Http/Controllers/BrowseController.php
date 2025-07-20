<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Services\CivitAIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
    public function blacklist(Request $request, File $file): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:255'
        ]);

        $file->update([
            'is_blacklisted' => true,
            'blacklist_reason' => $request->input('reason')
        ]);

        // Return JSON response for AJAX requests, redirect for regular requests
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Item has been blacklisted'
            ]);
        }

        return back()
            ->with('message', 'Item has been blacklisted');
    }
}
