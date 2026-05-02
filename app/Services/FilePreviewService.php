<?php

namespace App\Services;

use App\Models\File;
use App\Models\Reaction;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Support\Collection;

class FilePreviewService
{
    public const int FEED_REMOVED_PREVIEW_COUNT = 99999;

    public const int RECOVERED_PREVIEW_COUNT = 4;

    private const int AUTO_BLACKLIST_PREVIEW_COUNT = 2;

    public function __construct(
        private readonly FileBlacklistService $fileBlacklistService,
        private readonly LocalBrowseIndexSyncService $localBrowseIndexSyncService,
    ) {}

    /**
     * @return array{id: int, previewed_count: int, reaction: array{type: string}|null, auto_blacklisted: bool, blacklisted_at: string|null}
     */
    public function increment(File $file, int $userId, int $increments = 1): array
    {
        return $this->incrementMany(collect([$file]), $userId, $increments)[0];
    }

    /**
     * @param  iterable<int, File>  $files
     * @return array<int, array{id: int, previewed_count: int, reaction: array{type: string}|null, auto_blacklisted: bool, blacklisted_at: string|null}>
     */
    public function incrementMany(iterable $files, int $userId, int $increments = 1): array
    {
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

        File::query()
            ->whereIn('id', $fileIds)
            ->increment('previewed_count', max(1, $increments));

        File::query()
            ->whereIn('id', $fileIds)
            ->update(['previewed_at' => now()]);

        $freshFiles = File::query()
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy('id');

        $currentUserReactions = Reaction::query()
            ->where('user_id', $userId)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy('file_id');

        $filesToAutoBlacklist = [];
        $blacklistedFileIdsToMarkRemoved = [];

        foreach ($fileIds as $fileId) {
            /** @var File|null $file */
            $file = $freshFiles->get($fileId);
            if (! $file) {
                continue;
            }

            if ($file->blacklisted_at !== null) {
                $blacklistedFileIdsToMarkRemoved[] = (int) $file->id;

                continue;
            }

            /** @var Reaction|null $reaction */
            $reaction = $currentUserReactions->get($fileId);
            $reactionType = $reaction?->type;

            if (
                $reactionType === null
                && ! $this->isLocalSource($file)
                && (int) $file->previewed_count >= self::AUTO_BLACKLIST_PREVIEW_COUNT
            ) {
                $filesToAutoBlacklist[] = $file;
            }
        }

        if ($filesToAutoBlacklist !== []) {
            $this->fileBlacklistService->apply(
                $filesToAutoBlacklist,
                $userId,
                autoBlacklisted: true,
            );
        }

        if ($blacklistedFileIdsToMarkRemoved !== []) {
            File::query()
                ->whereIn('id', $blacklistedFileIdsToMarkRemoved)
                ->where('previewed_count', '<', self::FEED_REMOVED_PREVIEW_COUNT)
                ->update([
                    'previewed_count' => self::FEED_REMOVED_PREVIEW_COUNT,
                    'updated_at' => now(),
                ]);
        }

        $this->localBrowseIndexSyncService->syncFilesByIds($fileIds);
        $this->localBrowseIndexSyncService->syncReactionsForFileIds($fileIds);

        return $this->formatResults($fileIds, $userId);
    }

    /**
     * @param  array<int>  $fileIds
     * @return array<int, array{id: int, previewed_count: int, reaction: array{type: string}|null, auto_blacklisted: bool, blacklisted_at: string|null}>
     */
    private function formatResults(array $fileIds, int $userId): array
    {
        /** @var Collection<int, File> $files */
        $files = File::query()
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy('id');

        $reactions = Reaction::query()
            ->where('user_id', $userId)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy('file_id');

        return collect($fileIds)
            ->map(function (int $fileId) use ($files, $reactions): array {
                /** @var File $file */
                $file = $files->get($fileId);
                /** @var Reaction|null $reaction */
                $reaction = $reactions->get($fileId);

                return [
                    'id' => (int) $file->id,
                    'previewed_count' => (int) $file->previewed_count,
                    'reaction' => $reaction ? ['type' => $reaction->type] : null,
                    'auto_blacklisted' => (bool) $file->auto_blacklisted,
                    'blacklisted_at' => $file->blacklisted_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    private function isLocalSource(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === 'local';
    }
}
