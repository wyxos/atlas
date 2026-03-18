<?php

namespace App\Services;

use App\Enums\ActionType;
use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use App\Models\File;

class ContainerBlacklistService
{
    private const array POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    /**
     * Immediately apply a blacklist container action to files already attached to the container.
     *
     * @return array<int>
     */
    public function apply(Container $container): array
    {
        if ($container->blacklisted_at === null || $container->action_type !== ActionType::BLACKLIST) {
            return [];
        }

        $files = $container->files()
            ->select(['files.id', 'files.path', 'files.blacklisted_at'])
            ->whereNull('files.blacklisted_at')
            ->whereDoesntHave('reactions', fn ($query) => $query->whereIn('type', self::POSITIVE_REACTION_TYPES))
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

        foreach ($files->pluck('path')->filter()->all() as $path) {
            DeleteAutoDislikedFileJob::dispatch($path);
        }

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
