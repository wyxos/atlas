<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Container;
use App\Models\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ContainerModerationService extends BaseModerationService
{
    private ?Collection $blacklistedContainers = null;

    private array $fileContainerMap = [];

    /**
     * Apply container moderation rules to files based on their containers' action_type.
     *
     * @param  Collection<int, File>  $files
     * @return array{flaggedIds:array<int>, processedIds:array<int>}
     */
    public function moderate(Collection $files): array
    {
        // Initialize container data
        $this->blacklistedContainers = Container::whereNotNull('blacklisted_at')
            ->get()
            ->keyBy('id');

        // Get all container IDs for the files
        $fileIds = $files->pluck('id')->toArray();
        $this->fileContainerMap = DB::table('container_file')
            ->whereIn('file_id', $fileIds)
            ->get()
            ->groupBy('file_id')
            ->map(fn ($rows) => $rows->pluck('container_id')->toArray())
            ->toArray();

        return $this->process($files);
    }

    protected function hasRules(): bool
    {
        return $this->blacklistedContainers !== null && ! $this->blacklistedContainers->isEmpty();
    }

    protected function shouldSkipFile(File $file): bool
    {
        $containerIds = $this->fileContainerMap[$file->id] ?? [];

        return empty($containerIds);
    }

    protected function getMatchForFile(File $file): ?Container
    {
        $containerIds = $this->fileContainerMap[$file->id] ?? [];

        foreach ($containerIds as $containerId) {
            if (isset($this->blacklistedContainers[$containerId])) {
                return $this->blacklistedContainers[$containerId];
            }
        }

        return null;
    }

    protected function getActionType(object $match): string
    {
        return $match->action_type;
    }
}
