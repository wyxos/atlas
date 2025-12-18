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
     * Process files and apply moderation rules.
     *
     * @param  Collection<int, File>  $files
     * @return array{flaggedIds:array<int>, processedIds:array<int>}
     */
    public function process(Collection $files): array
    {
        if ($files->isEmpty()) {
            return $this->emptyResult();
        }

        // Check if there are any rules/containers to apply
        if (! $this->hasRules()) {
            return $this->emptyResult();
        }

        $flaggedIds = [];
        $autoDislikeFileIds = [];
        $blacklistFileIds = [];
        $filesToDelete = [];

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
            $this->handleActionType($actionType, $file, $flaggedIds, $autoDislikeFileIds, $blacklistFileIds, $filesToDelete);
        }

        // Process files
        $processedIds = $this->processFiles($autoDislikeFileIds, $blacklistFileIds, $filesToDelete);

        return [
            'flaggedIds' => $flaggedIds,
            'processedIds' => $processedIds,
        ];
    }

    /**
     * Handle action type for a file and collect it into appropriate arrays.
     */
    protected function handleActionType(
        string $actionType,
        File $file,
        array &$flaggedIds,
        array &$autoDislikeFileIds,
        array &$blacklistFileIds,
        array &$filesToDelete
    ): void {
        if ($actionType === ActionType::UI_COUNTDOWN) {
            $flaggedIds[] = $file->id;
        } elseif ($actionType === ActionType::AUTO_DISLIKE) {
            $autoDislikeFileIds[] = $file->id;
            if (! empty($file->path)) {
                $filesToDelete[] = $file->path;
            }
        } elseif ($actionType === ActionType::BLACKLIST) {
            $blacklistFileIds[] = $file->id;
            if (! empty($file->path)) {
                $filesToDelete[] = $file->path;
            }
        }
    }

    /**
     * Process files with auto-dislike and blacklist actions.
     *
     * @param  array<int>  $autoDislikeFileIds  File IDs to auto-dislike
     * @param  array<int>  $blacklistFileIds  File IDs to blacklist
     * @param  array<string>  $filesToDelete  File paths that need delete jobs
     * @return array<int> Combined processed file IDs
     */
    protected function processFiles(
        array $autoDislikeFileIds,
        array $blacklistFileIds,
        array $filesToDelete = []
    ): array {
        // Batch update auto-disliked files
        if (! empty($autoDislikeFileIds)) {
            File::whereIn('id', $autoDislikeFileIds)->update(['auto_disliked' => true]);

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
                }, $autoDislikeFileIds);

                // Check for existing reactions to avoid duplicates
                $existingReactions = Reaction::whereIn('file_id', $autoDislikeFileIds)
                    ->where('user_id', $user->id)
                    ->pluck('file_id')
                    ->toArray();

                $newReactionsToInsert = array_filter($reactionsToInsert, function ($reaction) use ($existingReactions) {
                    return ! in_array($reaction['file_id'], $existingReactions);
                });

                if (! empty($newReactionsToInsert)) {
                    Reaction::insert($newReactionsToInsert);
                }
            }
        }

        // Batch update blacklisted files
        if (! empty($blacklistFileIds)) {
            File::whereIn('id', $blacklistFileIds)->update(['blacklisted_at' => now()]);
        }

        // Dispatch delete jobs for files with paths
        foreach ($filesToDelete as $path) {
            DeleteAutoDislikedFileJob::dispatch($path);
        }

        return array_merge($autoDislikeFileIds, $blacklistFileIds);
    }

    /**
     * Create an empty result structure.
     *
     * @return array{flaggedIds:array<int>, processedIds:array<int>}
     */
    protected function emptyResult(): array
    {
        return [
            'flaggedIds' => [],
            'processedIds' => [],
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
     *
     * @return object|null The matched rule/container, or null if no match
     */
    abstract protected function getMatchForFile(File $file): ?object;

    /**
     * Get the action type from a matched rule/container.
     *
     * @param  object  $match  The matched rule/container
     * @return string The action type
     */
    abstract protected function getActionType(object $match): string;
}
