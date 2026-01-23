<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActionType;
use App\Jobs\DeleteAutoDislikedFileJob;
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
     * File paths that need delete jobs.
     *
     * @var array<string>
     */
    protected array $filesToDelete = [];

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

        foreach ($files as $file) {
            // Skip files already auto-disliked or blacklisted
            if ($file->auto_disliked || $file->blacklisted_at !== null) {
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

            // Track immediate actions (blacklist only - dislike shows countdown)
            if ($actionType === ActionType::BLACKLIST) {
                $this->immediateActions[$file->id] = [
                    'file_id' => $file->id,
                    'action_type' => $actionType,
                ];
            }

            $this->handleActionType($actionType, $file);
        }

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
        $this->filesToDelete = [];
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
            if (! empty($file->path)) {
                $this->filesToDelete[] = $file->path;
            }
        }
    }

    /**
     * Process files with auto-dislike and blacklist actions.
     */
    protected function processFiles(): array
    {
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
        }

        // Batch update blacklisted files
        if (! empty($this->blacklistFileIds)) {
            app(MetricsService::class)->applyBlacklistAdd($this->blacklistFileIds, false);
            File::whereIn('id', $this->blacklistFileIds)->update(['blacklisted_at' => now()]);
        }

        // Dispatch delete jobs for files with paths
        foreach ($this->filesToDelete as $path) {
            DeleteAutoDislikedFileJob::dispatch($path);
        }

        return array_merge($this->autoDislikeFileIds, $this->blacklistFileIds);
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
}
