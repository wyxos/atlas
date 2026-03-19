<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDeleteTabsRequest;
use App\Http\Requests\ReorderTabsRequest;
use App\Http\Requests\StoreTabRequest;
use App\Http\Requests\UpdateTabRequest;
use App\Models\Tab;
use App\Services\BrowseModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $params = $request->params ?? [];

        $tab = Tab::create([
            'user_id' => $userId,
            'label' => $request->label,
            'custom_label' => $this->normalizeCustomLabel($request->input('custom_label')),
            'params' => $params,
            'position' => $request->position ?? ($maxPosition + 1),
        ]);

        $tab->load('files.metadata');

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
        if (array_key_exists('custom_label', $validated)) {
            $validated['custom_label'] = $this->normalizeCustomLabel($validated['custom_label']);
        }

        $tab->update($validated);

        $tab->load('files.metadata');

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

    public function reorder(ReorderTabsRequest $request): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        $orderedIds = array_map('intval', $request->validated('ordered_ids'));
        $currentIds = Tab::forUser($userId)
            ->ordered()
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();

        $sortedOrderedIds = $orderedIds;
        $sortedCurrentIds = $currentIds;
        sort($sortedOrderedIds);
        sort($sortedCurrentIds);

        if ($sortedOrderedIds !== $sortedCurrentIds) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['The ordered tab list is stale. Reload tabs and try again.'],
            ]);
        }

        DB::transaction(function () use ($userId, $orderedIds) {
            foreach ($orderedIds as $position => $tabId) {
                Tab::forUser($userId)
                    ->where('id', $tabId)
                    ->update(['position' => $position]);
            }
        });

        return response()->json([
            'ordered_ids' => $orderedIds,
        ]);
    }

    public function destroyBatch(BulkDeleteTabsRequest $request): JsonResponse
    {
        /** @var Guard $auth */
        $auth = auth();
        /** @var int|null $userId */
        $userId = $auth->id();
        $ids = array_map('intval', $request->validated('ids'));
        $ownedIds = Tab::forUser($userId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
        $sortedIds = $ids;
        $sortedOwnedIds = $ownedIds;
        sort($sortedIds);
        sort($sortedOwnedIds);

        if ($sortedIds !== $sortedOwnedIds) {
            throw ValidationException::withMessages([
                'ids' => ['One or more tabs could not be found for this user.'],
            ]);
        }

        $nextActiveId = $request->filled('next_active_id')
            ? $request->integer('next_active_id')
            : null;

        if ($nextActiveId !== null && in_array($nextActiveId, $ids, true)) {
            throw ValidationException::withMessages([
                'next_active_id' => ['The next active tab cannot also be deleted.'],
            ]);
        }

        if ($nextActiveId !== null) {
            $nextActiveOwned = Tab::forUser($userId)
                ->where('id', $nextActiveId)
                ->exists();

            if (! $nextActiveOwned) {
                throw ValidationException::withMessages([
                    'next_active_id' => ['The next active tab is invalid.'],
                ]);
            }
        }

        $activeTabId = DB::transaction(function () use ($userId, $ids, $nextActiveId) {
            Tab::forUser($userId)
                ->whereIn('id', $ids)
                ->delete();

            if ($nextActiveId !== null) {
                Tab::forUser($userId)->update(['is_active' => false]);

                Tab::forUser($userId)
                    ->where('id', $nextActiveId)
                    ->update(['is_active' => true]);
            }

            return Tab::forUser($userId)
                ->where('is_active', true)
                ->value('id');
        });

        return response()->json([
            'deleted_ids' => $ids,
            'active_tab_id' => $activeTabId ? (int) $activeTabId : null,
        ]);
    }

    /**
     * Get a single tab with its items.
     * This endpoint returns the tab data including items for restoring a tab.
     */
    public function show(Tab $tab): JsonResponse
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
                'files.preview_url',
                'files.mime_type',
                'files.path',
                'files.preview_path',
                'files.poster_path',
                'files.downloaded',
                'files.blacklisted_at',
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
            ])
            ->orderByPivot('position')
            ->get();

        // Re-run the browse moderation pipeline so restored tabs follow the same blacklist behavior
        // as live browse results, including spared reacted files from blacklisted containers.
        $moderationResult = app(BrowseModerationService::class)->process($files, [
            'filterBlacklisted' => true,
        ]);
        $files = collect($moderationResult['files']);

        $items = [];

        if ($files->isNotEmpty()) {
            // Get page from params, default to 1
            $page = isset($tab->params['page']) && is_numeric($tab->params['page'])
                ? (int) $tab->params['page']
                : 1;
            $items = Tab::formatFilesToItems($files, $page, is_array($tab->params) ? $tab->params : []);
        }

        $params = $tab->params ?? (object) [];

        return response()->json([
            'tab' => [
                'id' => $tab->id,
                'label' => $tab->label,
                'custom_label' => $tab->custom_label,
                'params' => $params,
                'items' => $items,
                'position' => $tab->position ?? 0,
                'isActive' => $tab->is_active ?? false,
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

    private function normalizeCustomLabel(mixed $customLabel): ?string
    {
        if (! is_string($customLabel)) {
            return null;
        }

        $trimmed = trim($customLabel);

        return $trimmed === '' ? null : $trimmed;
    }
}
