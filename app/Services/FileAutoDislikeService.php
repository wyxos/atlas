<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Support\Facades\DB;

class FileAutoDislikeService
{
    public function __construct(
        private readonly DownloadedFileClearService $downloadedFileClearService,
        private readonly LocalBrowseIndexSyncService $localBrowseIndexSyncService,
        private readonly MetricsService $metricsService,
    ) {}

    /**
     * Apply backend-owned auto-dislike state and the target user's dislike reaction.
     *
     * @param  iterable<int, File>  $files
     * @return array<int>
     */
    public function apply(
        iterable $files,
        int $userId,
        bool $clearBlacklist = false,
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

        DB::transaction(function () use ($files, $userId, $clearBlacklist): void {
            $autoDislikeIds = $files
                ->filter(fn (File $file): bool => ! $file->auto_disliked)
                ->pluck('id')
                ->map(fn (mixed $fileId): int => (int) $fileId)
                ->all();

            $this->metricsService->applyAutoDislikeAdd($autoDislikeIds);

            foreach ($files as $file) {
                $existingReaction = Reaction::query()
                    ->where('file_id', $file->id)
                    ->where('user_id', $userId)
                    ->first();

                $wasBlacklisted = $file->blacklisted_at !== null;
                $isBlacklisted = $clearBlacklist ? false : $wasBlacklisted;

                if ($clearBlacklist && $wasBlacklisted) {
                    $this->metricsService->applyBlacklistClear($file, adjustUnreacted: false);
                }

                if ($existingReaction?->type !== 'dislike') {
                    $this->metricsService->applyReactionChange(
                        $file,
                        $existingReaction?->type,
                        'dislike',
                        $wasBlacklisted,
                        $isBlacklisted,
                    );
                }

                $updates = [
                    'auto_disliked' => true,
                    'updated_at' => now(),
                ];

                if ($clearBlacklist) {
                    $updates['blacklisted_at'] = null;
                }

                File::query()
                    ->whereKey($file->id)
                    ->update($updates);

                Reaction::query()->updateOrCreate(
                    [
                        'file_id' => $file->id,
                        'user_id' => $userId,
                    ],
                    [
                        'type' => 'dislike',
                    ],
                );
            }
        });

        if ($detachFromTabs) {
            app(TabFileService::class)->detachFilesFromUserTabs($userId, $fileIds);
        }

        $this->downloadedFileClearService->clearMany($files, queueDelete: $queueDelete);

        $this->localBrowseIndexSyncService->syncFilesByIds($fileIds);
        $this->localBrowseIndexSyncService->syncReactionsForFileIds($fileIds);

        return $fileIds;
    }
}
