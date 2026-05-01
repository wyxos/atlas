<?php

namespace App\Services;

use App\Models\Container;

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

        return app(FileBlacklistService::class)->apply($files, $userId, autoBlacklisted: true);
    }
}
