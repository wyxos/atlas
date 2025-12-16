<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ContainerBlacklistService
{
    /**
     * Apply container blacklist rules to files based on their containers' action_type.
     * - ui_countdown: Returns file IDs to flag with will_auto_dislike (reuses existing auto-dislike queue)
     * - auto_dislike: Immediately sets auto_disliked = true on files and dispatches delete job
     * - blacklist: Immediately sets blacklisted_at = now() on files and dispatches delete job
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
        $processedIds = []; // Files that were immediately processed (auto_dislike or blacklist)

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
                // Immediately auto-dislike
                $file->auto_disliked = true;
                $file->save();
                $processedIds[] = $file->id;

                // Dispatch delete job if file has a path
                if (! empty($file->path)) {
                    DeleteAutoDislikedFileJob::dispatch($file->path);
                }
            } elseif ($actionType === 'blacklist') {
                // Immediately blacklist
                $file->blacklisted_at = now();
                $file->save();
                $processedIds[] = $file->id;

                // Dispatch delete job if file has a path
                if (! empty($file->path)) {
                    DeleteAutoDislikedFileJob::dispatch($file->path);
                }
            }
        }

        return [
            'flaggedFileIds' => $flaggedFileIds,
            'processedIds' => $processedIds,
        ];
    }
}
