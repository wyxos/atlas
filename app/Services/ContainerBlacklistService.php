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
            ->whereNull('files.blacklisted_at')
            ->whereDoesntHave('reactions')
            ->get();

        $fileIds = $files
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if ($fileIds === []) {
            return [];
        }

        app(MetricsService::class)->applyBlacklistAdd($fileIds, false);

        File::query()
            ->whereIn('id', $fileIds)
            ->update(['blacklisted_at' => now()]);

        if (is_int($userId)) {
            app(TabFileService::class)->detachFilesFromUserTabs($userId, $fileIds);
        }

        app(DownloadedFileClearService::class)->clearMany($files, syncSearch: false, queueDelete: true);

        $this->syncSearch($fileIds);

        return $fileIds;
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
