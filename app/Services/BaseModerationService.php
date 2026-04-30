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
     * File IDs retained for legacy result shape.
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
     * Files to auto-dislike.
     *
     * @var array<int, File>
     */
    protected array $autoDislikeFiles = [];

    /**
     * File IDs to blacklist.
     *
     * @var array<int>
     */
    protected array $blacklistFileIds = [];

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

            if ($actionType === ActionType::DISLIKE) {
                $this->immediateActions[$file->id] = [
                    'file_id' => $file->id,
                    'action_type' => $actionType,
                ];
            } elseif ($actionType === ActionType::BLACKLIST) {
                $anyReactedFileIds ??= $this->resolveReactedFileIdsForAnyUser($files);

                // Any existing reaction should spare the file from backend blacklisting.
                if (isset($anyReactedFileIds[(int) $file->id])) {
                    continue;
                }

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
        $this->autoDislikeFiles = [];
        $this->blacklistFileIds = [];
        $this->immediateActions = [];
    }

    /**
     * Handle action type for a file and collect it into appropriate arrays.
     */
    protected function handleActionType(string $actionType, File $file): void
    {
        if ($actionType === ActionType::DISLIKE) {
            $this->autoDislikeFileIds[] = $file->id;
            $this->autoDislikeFiles[(int) $file->id] = $file;
        } elseif ($actionType === ActionType::BLACKLIST) {
            $this->blacklistFileIds[] = $file->id;
        }
    }

    /**
     * Process files with auto-dislike and blacklist actions.
     */
    protected function processFiles(): array
    {
        if (! empty($this->autoDislikeFileIds)) {
            $userId = Auth::id();
            if (is_int($userId)) {
                app(FileAutoDislikeService::class)->apply($this->autoDislikeFiles, $userId);
            }
        }

        // Batch update blacklisted files
        if (! empty($this->blacklistFileIds)) {
            $userId = Auth::id();
            app(FileBlacklistService::class)->apply(
                File::query()
                    ->whereIn('id', $this->blacklistFileIds)
                    ->get(),
                is_int($userId) ? $userId : null,
            );
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
