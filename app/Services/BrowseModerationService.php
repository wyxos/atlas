<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
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
        $flaggedIds = $fileModerationResult['flaggedIds'];
        $processedIds = $fileModerationResult['processedIds'];
        $immediateActions = $fileModerationResult['immediateActions'] ?? [];

        // Container moderation: apply container moderation rules after file moderation
        $containerModerationResult = app(ContainerModerationService::class)->moderate($files);
        $containerFlaggedIds = $containerModerationResult['flaggedIds'];
        $containerProcessedIds = $containerModerationResult['processedIds'];
        $containerImmediateActions = $containerModerationResult['immediateActions'] ?? [];

        $flaggedIds = array_merge($flaggedIds, $containerFlaggedIds);

        // Merge processed IDs from both moderation and container blacklist
        $processedIds = array_merge($processedIds, $containerProcessedIds);

        // Merge immediate actions from both moderation and container blacklist
        $immediateActions = array_merge($immediateActions, $containerImmediateActions);

        // Store files before filtering (needed for immediate actions formatting)
        $allFilesBeforeFilter = $files->keyBy('id');

        $blacklistedFileIds = array_map(
            static fn (array $action): int => (int) $action['file_id'],
            array_values(array_filter(
                $immediateActions,
                static fn (array $action): bool => ($action['action_type'] ?? null) === 'blacklist',
            )),
        );

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

        $filterAutoDisliked = (bool) ($context['filterAutoDisliked'] ?? false);
        $filterCurrentUserReacted = (bool) ($context['filterCurrentUserReacted'] ?? false);
        $currentUserReactedIdSet = $filterCurrentUserReacted
            ? $this->resolveCurrentUserReactedFileIds($files)
            : [];

        // Filter out permanently unavailable files and blacklisted files from browse/tab responses.
        $filteredFiles = $files
            ->reject(function ($file) use ($blacklistedIdSet, $currentUserReactedIdSet, $filterAutoDisliked, $filterBlacklisted, $filterCurrentUserReacted) {
                if ((bool) ($file->not_found ?? false)) {
                    return true;
                }

                if ($filterBlacklisted && isset($blacklistedIdSet[(int) $file->id])) {
                    return true;
                }

                if ($filterAutoDisliked && (bool) ($file->auto_disliked ?? false)) {
                    return true;
                }

                if ($filterCurrentUserReacted && isset($currentUserReactedIdSet[(int) $file->id])) {
                    return true;
                }

                return false;
            })
            ->values()
            ->all();

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

    /**
     * @return array<int, true>
     */
    private function resolveCurrentUserReactedFileIds(Collection $files): array
    {
        $userId = auth()->id();
        if (! is_int($userId)) {
            return [];
        }

        $reactedFileIds = [];
        $fileIdsToQuery = [];

        foreach ($files as $file) {
            if (! ($file instanceof File)) {
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
            ->whereIn('file_id', array_values(array_unique($fileIdsToQuery)))
            ->pluck('file_id');

        foreach ($queriedIds as $fileId) {
            $reactedFileIds[(int) $fileId] = true;
        }

        return $reactedFileIds;
    }
}
