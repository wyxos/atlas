<?php

namespace App\Services;

use App\Jobs\EvaluateContainerAutoBlacklist;
use App\Models\File;
use App\Models\Reaction;
use App\Services\Downloads\DownloadTransferRemovalService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Support\Facades\DB;

class FileBlacklistService
{
    public function __construct(
        private readonly DownloadedFileClearService $downloadedFileClearService,
        private readonly DownloadTransferRemovalService $downloadTransferRemovalService,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
        private readonly MetricsService $metricsService,
    ) {}

    /**
     * Apply backend-owned blacklist state and clear state that cannot coexist with blacklisting.
     *
     * @param  iterable<int, File>  $files
     * @return array<int>
     */
    public function apply(
        iterable $files,
        ?int $userId = null,
        bool $detachFromTabs = true,
        bool $queueDelete = true,
        ?int $minimumPreviewedCount = null,
        bool $autoBlacklisted = false,
        bool $queueContainerAutoBlacklistEvaluation = false,
    ): array {
        $files = collect($files)
            ->filter(fn (mixed $file): bool => $file instanceof File)
            ->keyBy(fn (File $file): int => (int) $file->id)
            ->values();

        if ($files->isEmpty()) {
            return [];
        }

        $fileIds = $files
            ->pluck('id')
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->all();
        $blacklistedAt = now();
        $newBlacklistIds = [];

        DB::transaction(function () use ($files, $fileIds, $blacklistedAt, $minimumPreviewedCount, $autoBlacklisted, &$newBlacklistIds): void {
            $newBlacklistIds = $files
                ->filter(fn (File $file): bool => $file->blacklisted_at === null)
                ->pluck('id')
                ->map(fn (mixed $fileId): int => (int) $fileId)
                ->all();

            $this->metricsService->applyBlacklistAdd($newBlacklistIds, $autoBlacklisted, $minimumPreviewedCount);

            $autoBlacklistAddIds = [];

            foreach ($files as $file) {
                if ($autoBlacklisted && ! (bool) $file->auto_blacklisted) {
                    $autoBlacklistAddIds[] = (int) $file->id;
                }

                if (! $autoBlacklisted) {
                    $this->metricsService->applyAutoBlacklistClear($file);
                }

                foreach ($this->reactionsForFile($file) as $reaction) {
                    $this->metricsService->applyReactionChange(
                        $file,
                        $reaction->type,
                        null,
                        $file->blacklisted_at !== null,
                        true,
                    );
                    $reaction->delete();
                }
            }

            $this->metricsService->applyAutoBlacklistAdd($autoBlacklistAddIds);

            if (
                is_int($minimumPreviewedCount)
                && $minimumPreviewedCount >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
            ) {
                $this->metricsService->applyBlacklistedFeedRemovedMark($fileIds);
            }

            if ($newBlacklistIds !== []) {
                File::query()
                    ->whereIn('id', $newBlacklistIds)
                    ->update([
                        'blacklisted_at' => $blacklistedAt,
                        'updated_at' => $blacklistedAt,
                    ]);
            }

            File::query()
                ->whereIn('id', $fileIds)
                ->update([
                    'auto_blacklisted' => $autoBlacklisted,
                    'updated_at' => now(),
                ]);

            if (is_int($minimumPreviewedCount) && $minimumPreviewedCount >= 0) {
                File::query()
                    ->whereIn('id', $fileIds)
                    ->where('previewed_count', '<', $minimumPreviewedCount)
                    ->update([
                        'previewed_count' => $minimumPreviewedCount,
                        'updated_at' => now(),
                    ]);
            }
        });

        if ($detachFromTabs && is_int($userId)) {
            app(TabFileService::class)->detachFilesFromUserTabs($userId, $fileIds);
        }

        $this->downloadTransferRemovalService->cancelActiveForFileIds($fileIds);
        $this->downloadedFileClearService->clearMany($files, queueDelete: $queueDelete, syncIndex: false);

        $this->libraryIndexSyncDispatcher->filesAndReactions($fileIds);

        if ($queueContainerAutoBlacklistEvaluation) {
            $this->dispatchContainerAutoBlacklistEvaluation($newBlacklistIds, $userId);
        }

        return $fileIds;
    }

    public function clear(File $file): File
    {
        $wasBlacklisted = $file->blacklisted_at !== null;
        $wasAutoBlacklisted = (bool) $file->auto_blacklisted;

        if (! $wasBlacklisted && ! $wasAutoBlacklisted) {
            return $file;
        }

        $hadTerminalPreviewCount = (int) $file->previewed_count >= FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;

        DB::transaction(function () use ($file, $wasBlacklisted, $wasAutoBlacklisted, $hadTerminalPreviewCount): void {
            if ($wasAutoBlacklisted) {
                $this->metricsService->applyAutoBlacklistClear($file, countAsManualBlacklisted: false);
            }

            if ($wasBlacklisted) {
                $this->metricsService->applyBlacklistClear(
                    $file,
                    adjustUnreacted: true,
                    wasAutoBlacklisted: $wasAutoBlacklisted,
                    hadTerminalPreviewCount: $hadTerminalPreviewCount,
                );
            }

            $updates = [
                'blacklisted_at' => null,
                'auto_blacklisted' => false,
                'updated_at' => now(),
            ];

            if ($wasBlacklisted && $hadTerminalPreviewCount) {
                $updates['previewed_count'] = FilePreviewService::RECOVERED_PREVIEW_COUNT;
            }

            File::query()
                ->whereKey($file->id)
                ->update($updates);
        });

        $file->refresh();
        $this->libraryIndexSyncDispatcher->filesAndReactions([(int) $file->id]);

        return $file;
    }

    /**
     * @return iterable<int, Reaction>
     */
    private function reactionsForFile(File $file): iterable
    {
        if ($file->relationLoaded('reactions')) {
            return $file->reactions;
        }

        return Reaction::query()
            ->where('file_id', $file->id)
            ->get();
    }

    /**
     * @param  array<int>  $fileIds
     */
    private function dispatchContainerAutoBlacklistEvaluation(array $fileIds, ?int $userId): void
    {
        if ($fileIds === []) {
            return;
        }

        $containerIds = DB::table('container_file')
            ->whereIn('file_id', $fileIds)
            ->distinct()
            ->pluck('container_id')
            ->map(fn (mixed $containerId): int => (int) $containerId)
            ->filter(fn (int $containerId): bool => $containerId > 0)
            ->values();

        foreach ($containerIds as $containerId) {
            EvaluateContainerAutoBlacklist::dispatch((int) $containerId, $userId);
        }
    }
}
