<?php

namespace App\Services;

use App\Enums\BlacklistPreviewedCountMode;
use App\Models\Container;

class ContainerBlacklistService
{
    /**
     * Immediately apply a blacklist container action to files already attached to the container.
     *
     * @return array<int>
     */
    public function apply(Container $container, ?int $userId = null, array $forceFeedRemovedFileIds = []): array
    {
        if ($container->blacklisted_at === null) {
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
                'files.auto_blacklisted',
            ])
            ->whereDoesntHave('reactions')
            ->get();

        $minimumPreviewedCount = $container->blacklist_previewed_count_mode === BlacklistPreviewedCountMode::FEED_REMOVED
            ? FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
            : null;

        $forceFeedRemovedIdLookup = collect($forceFeedRemovedFileIds)
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->flip();
        $blacklistService = app(FileBlacklistService::class);
        $blacklistedFileIds = [];

        foreach ([
            [
                'files' => $files->filter(fn ($file): bool => $forceFeedRemovedIdLookup->has((int) $file->id)),
                'minimumPreviewedCount' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
            ],
            [
                'files' => $files->reject(fn ($file): bool => $forceFeedRemovedIdLookup->has((int) $file->id)),
                'minimumPreviewedCount' => $minimumPreviewedCount,
            ],
        ] as $group) {
            if ($group['files']->isEmpty()) {
                continue;
            }

            array_push(
                $blacklistedFileIds,
                ...$blacklistService->apply(
                    $group['files'],
                    $userId,
                    minimumPreviewedCount: $group['minimumPreviewedCount'],
                    autoBlacklisted: true,
                ),
            );
        }

        return array_values(array_unique($blacklistedFileIds));
    }
}
