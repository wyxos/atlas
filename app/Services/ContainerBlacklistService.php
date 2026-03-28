<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Models\Container;
use App\Models\File;

class ContainerBlacklistService
{
    /**
     * Immediately apply a blacklist container action to files already attached to the container.
     *
     * @return array<int>
     */
    public function apply(Container $container, ?int $userId = null): array
    {
        if ($container->blacklisted_at === null || $container->action_type !== ActionType::BLACKLIST) {
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

        app(MetricsService::class)->applyBlacklistAdd($newlyBlacklistedIds, false);

        File::query()
            ->whereIn('id', $newlyBlacklistedIds)
            ->update(['blacklisted_at' => now()]);

        app(DownloadedFileClearService::class)->clearMany($newlyBlacklistedFiles, syncSearch: false, queueDelete: true);

        $this->syncSearch($newlyBlacklistedIds);

        return $newlyBlacklistedIds;
    }

    /**
     * @param  array<int>  $fileIds
     */
    private function syncSearch(array $fileIds): void
    {
        $fileIds = array_values(array_unique(array_map(fn ($id) => (int) $id, $fileIds)));
        $fileIds = array_values(array_filter($fileIds, fn ($id) => $id > 0));

        if ($fileIds === []) {
            return;
        }

        foreach (array_chunk($fileIds, 500) as $chunk) {
            File::query()
                ->whereIn('id', $chunk)
                ->with(['metadata', 'reactions'])
                ->get()
                ->searchable();
        }
    }
}
