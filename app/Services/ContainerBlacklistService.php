<?php

namespace App\Services;

use App\Enums\BlacklistPreviewedCountMode;
use App\Jobs\EvaluateContainerAutoBlacklist;
use App\Models\Container;
use Illuminate\Support\Facades\DB;

class ContainerBlacklistService
{
    public function __construct(private readonly MetricsService $metricsService) {}

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

    /**
     * Clear the blacklist state without changing the files that were previously affected by it.
     */
    public function clear(Container $container): bool
    {
        $updated = Container::query()
            ->whereKey($container->id)
            ->whereNotNull('blacklisted_at')
            ->update([
                'blacklisted_at' => null,
                'action_type' => null,
                'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return false;
        }

        $this->metricsService->incrementMetric(MetricsService::KEY_CONTAINERS_BLACKLISTED, -1);
        $container->refresh();

        return true;
    }

    /**
     * Queue one evaluation per container attached to the supplied files.
     */
    public function queueEvaluationForFiles(iterable $fileIds, ?int $userId = null): void
    {
        $fileIds = collect($fileIds)
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->filter(fn (int $fileId): bool => $fileId > 0)
            ->unique()
            ->values();

        if ($fileIds->isEmpty()) {
            return;
        }

        $containerIds = DB::table('container_file')
            ->whereIn('file_id', $fileIds->all())
            ->distinct()
            ->pluck('container_id')
            ->map(fn (mixed $containerId): int => (int) $containerId)
            ->filter(fn (int $containerId): bool => $containerId > 0)
            ->values();

        foreach ($containerIds as $containerId) {
            EvaluateContainerAutoBlacklist::dispatch($containerId, $userId);
        }
    }
}
