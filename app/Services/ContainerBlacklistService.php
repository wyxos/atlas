<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Models\Container;
use App\Models\File;
use App\Services\Local\LocalBrowseIndexSyncService;

class ContainerBlacklistService
{
    /**
     * Immediately apply a blacklist container action to files already attached to the container.
     *
     * @return array<int>
     */
    public function apply(Container $container, ?int $userId = null): array
    {
        if ($container->blacklisted_at === null) {
            return [];
        }

        if ($container->action_type === ActionType::DISLIKE) {
            if (! is_int($userId)) {
                return [];
            }

            $files = $container->files()
                ->select([
                    'files.id',
                    'files.path',
                    'files.preview_path',
                    'files.poster_path',
                    'files.downloaded',
                    'files.downloaded_at',
                    'files.download_progress',
                    'files.blacklisted_at',
                    'files.auto_disliked',
                ])
                ->whereNull('files.blacklisted_at')
                ->whereDoesntHave('reactions', fn ($query) => $query->where('user_id', $userId))
                ->get();

            return app(FileAutoDislikeService::class)->apply($files, $userId);
        }

        if ($container->action_type !== ActionType::BLACKLIST) {
            return [];
        }

        $files = $container->files()
            ->select([
                'files.id',
                'files.path',
                'files.preview_path',
                'files.poster_path',
                'files.downloaded',
                'files.downloaded_at',
                'files.download_progress',
                'files.blacklisted_at',
            ])
            ->whereDoesntHave('reactions')
            ->get();

        $fileIds = $files
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if ($fileIds === []) {
            return [];
        }

        if (is_int($userId)) {
            app(TabFileService::class)->detachFilesFromUserTabs($userId, $fileIds);
        }

        $newlyBlacklistedFiles = $files->whereNull('blacklisted_at')->values();
        $newlyBlacklistedIds = $newlyBlacklistedFiles
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if ($newlyBlacklistedIds === []) {
            return [];
        }

        app(MetricsService::class)->applyBlacklistAdd($newlyBlacklistedIds);

        File::query()
            ->whereIn('id', $newlyBlacklistedIds)
            ->update(['blacklisted_at' => now()]);

        app(DownloadedFileClearService::class)->clearMany($newlyBlacklistedFiles, queueDelete: true);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds($newlyBlacklistedIds);

        return $newlyBlacklistedIds;
    }
}
