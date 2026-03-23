<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActionType;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

abstract class BaseModerationService
{
    /**
     * File IDs flagged for UI countdown.
     *
     * @var array<int>
     */
    protected array $flaggedIds = [];

    /**
     * File IDs to auto-dislike.
     *
     * @var array<int>
     */
    protected array $autoDislikeFileIds = [];

    /**
     * File IDs to blacklist.
     *
     * @var array<int>
     */
    protected array $blacklistFileIds = [];

    /**
     * Files whose downloaded assets should be cleared.
     *
     * @var array<int, File>
     */
    protected array $filesToClear = [];

    /**
     * Immediate actions (auto_dislike/blacklist) tracked for toast notification.
     *
     * @var array<int, array{file_id:int, action_type:string}>
     */
    protected array $immediateActions = [];

    /**
     * Process files and apply moderation rules.
     */
    public function process(Collection $files): array
    {
        // Reset state for this processing run
        $this->resetState();

        if ($files->isEmpty()) {
            return $this->emptyResult();
        }

        // Check if there are any rules/containers to apply
        if (! $this->hasRules()) {
            return $this->emptyResult();
        }

        $reactedFileIds = $this->resolveReactedFileIdsForCurrentUser($files);
        $anyReactedFileIds = null;

        foreach ($files as $file) {
            // Skip files already auto-disliked or blacklisted
            if ($file->auto_disliked || $file->blacklisted_at !== null) {
                continue;
            }

            // If the current user already reacted to this file, do not auto-moderate it.
            // This prevents the UI from starting an auto-dislike countdown on already-reacted items.
            if (isset($reactedFileIds[(int) $file->id])) {
                continue;
            }

            // Check if file should be skipped (e.g., no prompt, no containers)
            if ($this->shouldSkipFile($file)) {
                continue;
            }

            // Get matched rule/container for this file
            $match = $this->getMatchForFile($file);
            if (! $match) {
                continue;
            }

            $actionType = $this->getActionType($match);

            if ($actionType === ActionType::BLACKLIST) {
                $anyReactedFileIds ??= $this->resolveReactedFileIdsForAnyUser($files);

                // Any existing reaction should spare the file from auto-blacklisting.
                if (isset($anyReactedFileIds[(int) $file->id])) {
                    continue;
                }

                // Track immediate actions (blacklist only - dislike shows countdown)
                $this->immediateActions[$file->id] = [
                    'file_id' => $file->id,
                    'action_type' => $actionType,
                ];
            }

            $this->recordMatch($file, $match, $actionType);
            $this->handleActionType($actionType, $file);
        }

        $this->flushRecordedMatches();

        // Process files
        $processedIds = $this->processFiles();

        return [
            'flaggedIds' => $this->flaggedIds,
            'processedIds' => $processedIds,
            'immediateActions' => array_values($this->immediateActions),
        ];
    }

    /**
     * Reset all state arrays for a new processing run.
     */
    protected function resetState(): void
    {
        $this->flaggedIds = [];
        $this->autoDislikeFileIds = [];
        $this->blacklistFileIds = [];
        $this->filesToClear = [];
        $this->immediateActions = [];
    }

    /**
     * Handle action type for a file and collect it into appropriate arrays.
     */
    protected function handleActionType(string $actionType, File $file): void
    {
        if ($actionType === ActionType::DISLIKE) {
            $this->flaggedIds[] = $file->id;
        } elseif ($actionType === ActionType::BLACKLIST) {
            $this->blacklistFileIds[] = $file->id;
            if (
                (bool) $file->downloaded
                || is_string($file->path) && $file->path !== ''
                || is_string($file->preview_path) && $file->preview_path !== ''
                || is_string($file->poster_path) && $file->poster_path !== ''
            ) {
                $this->filesToClear[(int) $file->id] = $file;
            }
        }
    }

    /**
     * Process files with auto-dislike and blacklist actions.
     */
    protected function processFiles(): array
    {
        $syncSearch = function (array $fileIds): void {
            if ($fileIds === []) {
                return;
            }

            $ids = array_values(array_unique(array_map(fn ($id) => (int) $id, $fileIds)));
            $ids = array_values(array_filter($ids, fn ($id) => $id > 0));
            if ($ids === []) {
                return;
            }

            foreach (array_chunk($ids, 500) as $chunk) {
                // Ensure toSearchableArray() doesn't cause N+1 queries.
                File::query()
                    ->whereIn('id', $chunk)
                    ->with(['metadata', 'reactions'])
                    ->get()
                    ->searchable();
            }
        };

        // Batch update auto-disliked files
        if (! empty($this->autoDislikeFileIds)) {
            File::whereIn('id', $this->autoDislikeFileIds)->update(['auto_disliked' => true]);

            // Batch create dislike reactions for auto-disliked files
            $user = Auth::user();
            if ($user) {
                $reactionsToInsert = array_map(function ($fileId) use ($user) {
                    return [
                        'file_id' => $fileId,
                        'user_id' => $user->id,
                        'type' => 'dislike',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $this->autoDislikeFileIds);

                // Check for existing reactions to avoid duplicates
                $existingReactions = Reaction::whereIn('file_id', $this->autoDislikeFileIds)
                    ->where('user_id', $user->id)
                    ->pluck('file_id')
                    ->toArray();

                $newReactionsToInsert = array_filter($reactionsToInsert, function ($reaction) use ($existingReactions) {
                    return ! in_array($reaction['file_id'], $existingReactions);
                });

                if (! empty($newReactionsToInsert)) {
                    app(MetricsService::class)->applyDislikeInsert(array_map(
                        fn ($reaction) => (int) $reaction['file_id'],
                        $newReactionsToInsert
                    ));
                    Reaction::insert($newReactionsToInsert);
                }
            }

            // Keep Typesense in sync (auto_disliked + dislike reaction arrays).
            $syncSearch($this->autoDislikeFileIds);
        }

        // Batch update blacklisted files
        if (! empty($this->blacklistFileIds)) {
            app(MetricsService::class)->applyBlacklistAdd($this->blacklistFileIds, false);
            File::whereIn('id', $this->blacklistFileIds)->update(['blacklisted_at' => now()]);

            $userId = Auth::id();
            if (is_int($userId)) {
                app(TabFileService::class)->detachFilesFromUserTabs($userId, $this->blacklistFileIds);
            }

            $filesToClear = collect($this->filesToClear)
                ->only($this->blacklistFileIds)
                ->values();

            if ($filesToClear->isNotEmpty()) {
                app(DownloadedFileClearService::class)->clearMany($filesToClear, syncSearch: false, queueDelete: true);
            }

            // Keep Typesense in sync (blacklisted flags).
            $syncSearch($this->blacklistFileIds);
        }

        return array_merge($this->autoDislikeFileIds, $this->blacklistFileIds);
    }

    /**
     * Build a lookup of file IDs that the current user has reacted to.
     *
     * @return array<int, true>
     */
    protected function resolveReactedFileIdsForCurrentUser(Collection $files): array
    {
        if (! Auth::check()) {
            return [];
        }

        $userId = Auth::id();
        if (! is_int($userId)) {
            return [];
        }

        $reactedFileIds = [];
        $fileIdsToQuery = [];

        foreach ($files as $file) {
            if (! ($file instanceof File)) {
                continue;
            }

            // Keep this consistent with process() where already-processed files are skipped.
            if ($file->auto_disliked || $file->blacklisted_at !== null) {
                continue;
            }

            if ($file->relationLoaded('reaction')) {
                if ($file->getRelation('reaction')) {
                    $reactedFileIds[(int) $file->id] = true;
                }

                continue;
            }

            $fileIdsToQuery[] = (int) $file->id;
        }

        if ($fileIdsToQuery === []) {
            return $reactedFileIds;
        }

        $queriedIds = Reaction::query()
            ->where('user_id', $userId)
            ->whereIn('file_id', $fileIdsToQuery)
            ->pluck('file_id');

        foreach ($queriedIds as $fileId) {
            $reactedFileIds[(int) $fileId] = true;
        }

        return $reactedFileIds;
    }

    /**
     * Build a lookup of file IDs that have any reaction by any user.
     *
     * @return array<int, true>
     */
    protected function resolveReactedFileIdsForAnyUser(Collection $files): array
    {
        $reactedFileIds = [];
        $fileIdsToQuery = [];

        foreach ($files as $file) {
            if (! ($file instanceof File)) {
                continue;
            }

            if ($file->auto_disliked || $file->blacklisted_at !== null) {
                continue;
            }

            if ($file->relationLoaded('reactions')) {
                if ($file->getRelation('reactions')->isNotEmpty()) {
                    $reactedFileIds[(int) $file->id] = true;
                }

                continue;
            }

            $fileIdsToQuery[] = (int) $file->id;
        }

        if ($fileIdsToQuery === []) {
            return $reactedFileIds;
        }

        $queriedIds = Reaction::query()
            ->whereIn('file_id', $fileIdsToQuery)
            ->distinct()
            ->pluck('file_id');

        foreach ($queriedIds as $fileId) {
            $reactedFileIds[(int) $fileId] = true;
        }

        return $reactedFileIds;
    }

    /**
     * Create an empty result structure.
     */
    protected function emptyResult(): array
    {
        return [
            'flaggedIds' => [],
            'processedIds' => [],
            'immediateActions' => [],
        ];
    }

    /**
     * Check if there are any rules/containers to apply.
     */
    abstract protected function hasRules(): bool;

    /**
     * Check if a file should be skipped (e.g., no prompt, no containers).
     */
    abstract protected function shouldSkipFile(File $file): bool;

    /**
     * Get the matched rule/container for a file.
     */
    abstract protected function getMatchForFile(File $file): ?object;

    /**
     * Get the action type from a matched rule/container.
     */
    abstract protected function getActionType(object $match): string;

    /**
     * Record match metadata (e.g. to show which rule flagged a file for auto-dislike).
     */
    protected function recordMatch(File $file, object $match, string $actionType): void
    {
        // Default: no-op.
    }

    /**
     * Flush any recorded match metadata to persistent storage.
     */
    protected function flushRecordedMatches(): void
    {
        // Default: no-op.
    }
}
