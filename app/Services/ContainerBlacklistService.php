<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ContainerBlacklistService
{
    /**
     * Apply container blacklist rules to files based on their containers' action_type.
     * - ui_countdown: Returns file IDs to flag with will_auto_dislike (reuses existing auto-dislike queue)
     * - auto_dislike: Immediately sets auto_disliked = true, creates dislike reaction, and dispatches delete job
     * - blacklist: Immediately sets blacklisted_at = now() and dispatches delete job
     *
     * @param  Collection<int, \App\Models\File>  $files
     * @return array{flaggedFileIds:array<int>, processedIds:array<int>}
     */
    public function filterBannedContainers(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [
                'flaggedFileIds' => [],
                'processedIds' => [],
            ];
        }

        // Get all blacklisted containers
        $blacklistedContainers = Container::whereNotNull('blacklisted_at')
            ->get()
            ->keyBy('id');

        if ($blacklistedContainers->isEmpty()) {
            return [
                'flaggedFileIds' => [],
                'processedIds' => [],
            ];
        }

        $flaggedFileIds = []; // File IDs for ui_countdown action type (will use existing auto-dislike queue)
        $autoDislikeFileIds = []; // Files to auto-dislike
        $blacklistFileIds = []; // Files to blacklist
        $filesToDelete = []; // Files with paths that need delete jobs

        // Get all container IDs for the files
        $fileIds = $files->pluck('id')->toArray();
        $fileContainerMap = DB::table('container_file')
            ->whereIn('file_id', $fileIds)
            ->get()
            ->groupBy('file_id')
            ->map(fn ($rows) => $rows->pluck('container_id')->toArray())
            ->toArray();

        foreach ($files as $file) {
            // Skip files already auto-disliked or blacklisted
            if ($file->auto_disliked || $file->blacklisted_at !== null) {
                continue;
            }

            // Get containers for this file
            $containerIds = $fileContainerMap[$file->id] ?? [];
            if (empty($containerIds)) {
                continue;
            }

            // Check if any of the file's containers are blacklisted
            $matchedContainer = null;
            foreach ($containerIds as $containerId) {
                if (isset($blacklistedContainers[$containerId])) {
                    $matchedContainer = $blacklistedContainers[$containerId];
                    break;
                }
            }

            if (! $matchedContainer) {
                continue;
            }

            $actionType = $matchedContainer->action_type;

            // Handle different action types
            if ($actionType === 'ui_countdown') {
                // Flag file for UI countdown - will reuse existing auto-dislike queue
                $flaggedFileIds[] = $file->id;
            } elseif ($actionType === 'auto_dislike') {
                // Collect for batch processing
                $autoDislikeFileIds[] = $file->id;
                if (! empty($file->path)) {
                    $filesToDelete[] = $file->path;
                }
            } elseif ($actionType === 'blacklist') {
                // Collect for batch processing
                $blacklistFileIds[] = $file->id;
                if (! empty($file->path)) {
                    $filesToDelete[] = $file->path;
                }
            }
        }

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

        $processedIds = array_merge($autoDislikeFileIds, $blacklistFileIds);

        return [
            'flaggedFileIds' => $flaggedFileIds,
            'processedIds' => $processedIds,
        ];
    }
}
