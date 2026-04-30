<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Support\Facades\DB;

class FileBlacklistService
{
    public function __construct(
        private readonly DownloadedFileClearService $downloadedFileClearService,
        private readonly LocalBrowseIndexSyncService $localBrowseIndexSyncService,
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

        DB::transaction(function () use ($files, $fileIds, $blacklistedAt): void {
            $newBlacklistIds = $files
                ->filter(fn (File $file): bool => $file->blacklisted_at === null)
                ->pluck('id')
                ->map(fn (mixed $fileId): int => (int) $fileId)
                ->all();

            $this->metricsService->applyBlacklistAdd($newBlacklistIds);

            foreach ($files as $file) {
                $this->metricsService->applyAutoDislikeClear($file);

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
                ->where('auto_disliked', true)
                ->update([
                    'auto_disliked' => false,
                    'updated_at' => now(),
                ]);
        });

        if ($detachFromTabs && is_int($userId)) {
            app(TabFileService::class)->detachFilesFromUserTabs($userId, $fileIds);
        }

        $this->downloadedFileClearService->clearMany($files, queueDelete: $queueDelete);

        $this->localBrowseIndexSyncService->syncFilesByIds($fileIds);
        $this->localBrowseIndexSyncService->syncReactionsForFileIds($fileIds);

        return $fileIds;
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
}
