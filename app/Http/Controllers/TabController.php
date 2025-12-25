<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTabRequest;
use App\Http\Requests\UpdateTabRequest;
use App\Models\Tab;
use Illuminate\Http\JsonResponse;

class TabController extends Controller
{
    /**
     * Get all tabs for the authenticated user.
     * Note: items are NOT loaded here to support 1000+ tabs efficiently.
     * Use the items() method to load items for a specific tab when needed.
     * No file-related information is included in the response.
     */
    public function index(): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        $tabs = Tab::forUser($userId)
            ->ordered()
            ->get();

        return response()->json($tabs);
    }

    /**
     * Create a new tab.
     */
    public function store(StoreTabRequest $request): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Get the highest position for this user's tabs
        $maxPosition = Tab::forUser($userId)
            ->max('position') ?? -1;

        $queryParams = $request->query_params ?? [];
        if (! isset($queryParams['sourceType'])) {
            $queryParams['sourceType'] = 'online';
        }

        $tab = Tab::create([
            'user_id' => $userId,
            'label' => $request->label,
            'query_params' => $queryParams,
            'position' => $request->position ?? ($maxPosition + 1),
        ]);

        // Sync files if file_ids are provided
        if ($request->has('file_ids') && is_array($request->file_ids)) {
            $fileIds = $request->file_ids;
            $syncData = [];
            foreach ($fileIds as $index => $fileId) {
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
     * Update a tab.
     */
    public function update(UpdateTabRequest $request, Tab $tab): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Ensure user owns this tab
        if ($tab->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validated();

        // Extract file_ids before updating (they're not a fillable attribute)
        $fileIds = $validated['file_ids'] ?? null;
        unset($validated['file_ids']);

        $tab->update($validated);

        // Sync files if file_ids are provided
        if ($fileIds !== null && is_array($fileIds)) {
            $syncData = [];
            foreach ($fileIds as $index => $fileId) {
                $syncData[$fileId] = ['position' => $index];
            }
            $tab->files()->sync($syncData);
        }

        $tab->load('files.metadata');

        // Add file_ids to response for frontend compatibility
        $tab->file_ids = $tab->files->pluck('id')->toArray();

        return response()->json($tab);
    }

    /**
     * Delete a tab.
     */
    public function destroy(Tab $tab): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Ensure user owns this tab
        if ($tab->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        $tab->delete();

        return response()->json(['message' => 'Tab deleted successfully']);
    }

    /**
     * Get items for a specific tab.
     * This endpoint is used to lazy-load items when restoring a tab.
     */
    public function items(Tab $tab): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Ensure user owns this tab
        if ($tab->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        // Optimize query: only select columns we need and eager load metadata efficiently
        // This prevents loading unnecessary data and reduces memory usage
        // For tabs with many files, this significantly improves performance
        $files = $tab->files()
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
            $page = isset($tab->query_params['page']) && is_numeric($tab->query_params['page'])
                ? (int) $tab->query_params['page']
                : 1;
            $itemsData = Tab::formatFilesToItems($files, $page);
        }

        $queryParams = $tab->query_params ?? [];

        return response()->json([
            'items' => $itemsData,
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'queryParams' => $queryParams,
                'sourceType' => $queryParams['sourceType'] ?? 'online',
            ],
        ]);
    }

    /**
     * Update tab position.
     */
    public function updatePosition(Tab $tab): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Ensure user owns this tab
        if ($tab->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        $request = request();
        $request->validate([
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $tab->update([
            'position' => $request->position,
        ]);

        return response()->json($tab);
    }

    /**
     * Set a tab as active.
     * This will deactivate all other tabs for the user and activate the specified tab.
     */
    public function setActive(Tab $tab): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        // Ensure user owns this tab
        if ($tab->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }

        // Deactivate all other tabs for this user
        Tab::forUser($userId)
            ->where('id', '!=', $tab->id)
            ->update(['is_active' => false]);

        // Activate this tab
        $tab->update(['is_active' => true]);

        return response()->json($tab);
    }

    /**
     * Delete all tabs for the authenticated user.
     */
    public function deleteAll(): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        $deletedCount = Tab::forUser($userId)->delete();

        return response()->json([
            'message' => 'All tabs deleted successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }
}
