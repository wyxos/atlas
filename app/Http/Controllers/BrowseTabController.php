<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrowseTabRequest;
use App\Http\Requests\UpdateBrowseTabRequest;
use App\Models\BrowseTab;
use Illuminate\Http\JsonResponse;

class BrowseTabController extends Controller
{
    /**
     * Get all tabs for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $tabs = BrowseTab::forUser(auth()->id())
            ->ordered()
            ->get();

        // Eager load files and format them for each tab
        $tabs->each(function (BrowseTab $tab) {
            if ($tab->file_ids && count($tab->file_ids) > 0) {
                // file_ids contains referrer URLs
                $files = \App\Models\File::with('metadata')
                    ->whereIn('referrer_url', $tab->file_ids)
                    ->get()
                    ->sortBy(function ($file) use ($tab) {
                        // Maintain order based on file_ids array
                        return array_search($file->referrer_url, $tab->file_ids);
                    })
                    ->values();

                // Format files into items structure
                // Get page from query_params, default to 1
                $page = isset($tab->query_params['page']) && is_numeric($tab->query_params['page'])
                    ? (int) $tab->query_params['page']
                    : 1;
                $tab->items_data = BrowseTab::formatFilesToItems($files, $page);
            } else {
                $tab->items_data = [];
            }
        });

        return response()->json($tabs);
    }

    /**
     * Create a new browse tab.
     */
    public function store(StoreBrowseTabRequest $request): JsonResponse
    {
        // Get the highest position for this user's tabs
        $maxPosition = BrowseTab::forUser(auth()->id())
            ->max('position') ?? -1;

        $tab = BrowseTab::create([
            'user_id' => auth()->id(),
            'label' => $request->label,
            'query_params' => $request->query_params,
            'file_ids' => $request->file_ids,
            'position' => $request->position ?? ($maxPosition + 1),
        ]);

        return response()->json($tab, 201);
    }

    /**
     * Update a browse tab.
     */
    public function update(UpdateBrowseTabRequest $request, BrowseTab $browseTab): JsonResponse
    {
        $browseTab->update($request->validated());

        return response()->json($browseTab);
    }

    /**
     * Delete a browse tab.
     */
    public function destroy(BrowseTab $browseTab): JsonResponse
    {
        // Ensure user owns this tab
        if ($browseTab->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $browseTab->delete();

        return response()->json(['message' => 'Tab deleted successfully']);
    }

    /**
     * Update tab position.
     */
    public function updatePosition(BrowseTab $browseTab): JsonResponse
    {
        // Ensure user owns this tab
        if ($browseTab->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $request = request();
        $request->validate([
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $browseTab->update([
            'position' => $request->position,
        ]);

        return response()->json($browseTab);
    }
}
