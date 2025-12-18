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
     * Note: items_data is NOT loaded here to support 1000+ tabs efficiently.
     * Use the items() method to load items for a specific tab when needed.
     * No file-related information is included in the response.
     */
    public function index(): JsonResponse
    {
        $tabs = BrowseTab::forUser(auth()->id())
            ->ordered()
            ->get();

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
     * Get items for a specific tab.
     * This endpoint is used to lazy-load items when restoring a tab.
     */
    public function items(BrowseTab $browseTab): JsonResponse
    {
        // Ensure user owns this tab
        if ($browseTab->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        // Optimize query: only select columns we need and eager load metadata efficiently
        // This prevents loading unnecessary data and reduces memory usage
        // For tabs with many files, this significantly improves performance
        $files = $browseTab->files()
            ->select([
                'files.id',
                'files.url',
                'files.thumbnail_url',
                'files.mime_type',
                'files.listing_metadata',
                'files.previewed_count',
                'files.seen_count',
                'files.auto_disliked',
            ])
            ->with([
                'metadata' => function ($query) {
                    // Load the payload column from metadata (longtext, needed for dimensions and prompt)
                    $query->select('id', 'file_id', 'payload');
                },
                'containers',
            ])
            ->orderByPivot('position')
            ->get();

        // Re-run moderation on files to catch files that should now be auto-disliked
        // (e.g., if moderation rules were added/activated after files were added to tab)
        $fileModerationService = app(\App\Services\FileModerationService::class);
        $moderationResult = $fileModerationService->moderate($files);
        $processedIds = $moderationResult['processedIds'];

        // Filter out files that were just auto-disliked or blacklisted by moderation
        // Also filter out files that are already marked as auto-disliked or blacklisted
        $files = $files->reject(function ($file) use ($processedIds) {
            return in_array($file->id, $processedIds, true)
                || $file->auto_disliked
                || $file->blacklisted_at !== null;
        });

        $itemsData = [];

        if ($files->isNotEmpty()) {
            // Get page from query_params, default to 1
            $page = isset($browseTab->query_params['page']) && is_numeric($browseTab->query_params['page'])
                ? (int) $browseTab->query_params['page']
                : 1;
            $itemsData = BrowseTab::formatFilesToItems($files, $page);
        }

        return response()->json([
            'items_data' => $itemsData,
            'file_ids' => $files->pluck('id')->toArray(),
        ]);
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

    /**
     * Set a tab as active.
     * This will deactivate all other tabs for the user and activate the specified tab.
     */
    public function setActive(BrowseTab $browseTab): JsonResponse
    {
        // Ensure user owns this tab
        if ($browseTab->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        // Deactivate all other tabs for this user
        BrowseTab::forUser(auth()->id())
            ->where('id', '!=', $browseTab->id)
            ->update(['is_active' => false]);

        // Activate this tab
        $browseTab->update(['is_active' => true]);

        return response()->json($browseTab);
    }

    /**
     * Delete all tabs for the authenticated user.
     */
    public function deleteAll(): JsonResponse
    {
        $deletedCount = BrowseTab::forUser(auth()->id())->delete();

        return response()->json([
            'message' => 'All tabs deleted successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }
}
