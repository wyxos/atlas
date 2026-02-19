<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Collection;

class BrowseModerationService
{
    /**
     * Process moderation for browse files.
     */
    public function process(Collection|array $files, array $context = []): array
    {
        $files = $files instanceof Collection ? $files : collect($files);

        // File moderation: apply rules based on action_type
        $fileModerationResult = app(FileModerationService::class)->moderate($files);
        $flaggedIds = $fileModerationResult['flaggedIds']; // Files matching rules with 'dislike' action type
        $processedIds = $fileModerationResult['processedIds'];
        $immediateActions = $fileModerationResult['immediateActions'] ?? [];

        // Container moderation: apply container moderation rules after file moderation
        $containerModerationResult = app(ContainerModerationService::class)->moderate($files);
        $containerFlaggedIds = $containerModerationResult['flaggedIds']; // Files matching container blacklist with 'dislike' action type
        $containerProcessedIds = $containerModerationResult['processedIds'];
        $containerImmediateActions = $containerModerationResult['immediateActions'] ?? [];

        // Merge flagged file IDs from both moderation rules and container blacklist rules
        // Both include files that match rules with 'dislike' action type (will show will_auto_dislike = true in UI)
        $flaggedIds = array_merge($flaggedIds, $containerFlaggedIds);

        // Merge processed IDs from both moderation and container blacklist
        $processedIds = array_merge($processedIds, $containerProcessedIds);

        // Merge immediate actions from both moderation and container blacklist
        $immediateActions = array_merge($immediateActions, $containerImmediateActions);

        // Store files before filtering (needed for immediate actions formatting)
        $allFilesBeforeFilter = $files->keyBy('id');

        // Extract file IDs that were blacklisted (not auto-disliked) from immediate actions
        // immediateActions only contains blacklisted files (auto-disliked files are not in immediateActions)
        $blacklistedFileIds = array_column($immediateActions, 'file_id');

        $filterBlacklisted = (bool) ($context['filterBlacklisted'] ?? true);

        $blacklistedIdSet = [];
        if ($filterBlacklisted) {
            $fileIds = $files
                ->pluck('id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $alreadyBlacklistedIds = empty($fileIds)
                ? []
                : File::query()
                    ->whereIn('id', $fileIds)
                    ->whereNotNull('blacklisted_at')
                    ->pluck('id')
                    ->all();

            $blacklistedIdSet = array_fill_keys(
                array_map('intval', array_merge($blacklistedFileIds, $alreadyBlacklistedIds)),
                true
            );
        }

        // Filter out only blacklisted files from response (auto-disliked files should be shown)
        // This includes files that were blacklisted in this request (via immediateActions)
        // and files that were already blacklisted (queried in one batch).
        $filteredFiles = $filterBlacklisted
            ? $files->reject(function ($file) use ($blacklistedIdSet) {
                return isset($blacklistedIdSet[(int) $file->id]);
            })->values()->all()
            : $files->values()->all();

        // Format immediately processed files (auto-disliked/blacklisted) for frontend toast notifications
        // These are files that were immediately processed (before they were filtered out)
        $immediatelyProcessedFiles = [];
        if (! empty($immediateActions)) {
            // Extract file IDs and create action_type map
            $immediateFileIds = array_column($immediateActions, 'file_id');
            $actionTypeMap = array_column($immediateActions, 'action_type', 'file_id');

            // Filter files directly from collection and map to desired structure
            $immediatelyProcessedFiles = $allFilesBeforeFilter
                ->only($immediateFileIds)
                ->map(fn ($file) => [
                    'id' => $file->id,
                    'action_type' => $actionTypeMap[$file->id] ?? 'dislike',
                    'thumbnail' => $file->preview_url ?? $file->url,
                ])
                ->values()
                ->all();
        }

        return [
            'files' => $filteredFiles,
            'flaggedIds' => $flaggedIds,
            'immediateActions' => $immediatelyProcessedFiles,
        ];
    }
}
