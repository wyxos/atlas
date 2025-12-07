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
            ->with(['files.metadata'])
            ->ordered()
            ->get();

        // Format files into items structure for each tab
        $tabs->each(function (BrowseTab $tab) {
            $files = $tab->files;
            if ($files->isNotEmpty()) {
                // Get page from query_params, default to 1
                $page = isset($tab->query_params['page']) && is_numeric($tab->query_params['page'])
                    ? (int) $tab->query_params['page']
                    : 1;
                $tab->items_data = BrowseTab::formatFilesToItems($files, $page);
            } else {
                $tab->items_data = [];
            }
            // Add file_ids to response for frontend compatibility
            $tab->file_ids = $tab->files->pluck('id')->toArray();
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
            'position' => $request->position ?? ($maxPosition + 1),
        ]);

        // Sync files with positions if provided
        if ($request->has('file_ids') && is_array($request->file_ids) && count($request->file_ids) > 0) {
            $syncData = [];
            foreach ($request->file_ids as $index => $fileId) {
                $syncData[$fileId] = ['position' => $index];
            }
            $tab->files()->sync($syncData);
        }

        $tab->load('files.metadata');
        
        // Add file_ids to response for frontend compatibility
        $tab->file_ids = $tab->files->pluck('id')->toArray();

        return response()->json($tab, 201);
    }

    /**
     * Update a browse tab.
     */
    public function update(UpdateBrowseTabRequest $request, BrowseTab $browseTab): JsonResponse
    {
        // Ensure user owns this tab
        if ($browseTab->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validated();
        $fileIds = $validated['file_ids'] ?? null;
        
        // Remove file_ids from validated data before updating
        unset($validated['file_ids']);

        $browseTab->update($validated);

        // Sync files with positions if provided
        if ($fileIds !== null) {
            if (is_array($fileIds) && count($fileIds) > 0) {
                $syncData = [];
                foreach ($fileIds as $index => $fileId) {
                    $syncData[$fileId] = ['position' => $index];
                }
                $browseTab->files()->sync($syncData);
            } else {
                // Empty array means remove all files
                $browseTab->files()->sync([]);
            }
        }

        $browseTab->load('files.metadata');
        
        // Add file_ids to response for frontend compatibility
        $browseTab->file_ids = $browseTab->files->pluck('id')->toArray();

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
