<?php

namespace App\Services\Extension;

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\ContainerBlacklistService;
use App\Services\FileMetricClassifier;
use App\Services\FilePreviewService;
use App\Services\FileReactionService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Services\MetricsService;
use App\Services\TabFileService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExtensionBatchReactionService
{
    /**
     * @param  iterable<int, File>  $files
     * @param  array{
     *     queueDownload?: bool,
     *     forceDownload?: bool,
     *     downloadRuntimeContext?: array<string, mixed>,
     *     queueLibrarySync?: bool
     * }  $options
     * @return array<int, array{reaction: array{type: string}|null, reacted_at: string|null, changed: bool}>
     */
    public function setMany(
        iterable $files,
        User $user,
        string $type,
        array $options = [],
    ): array {
        $files = collect($files)
            ->filter(fn (mixed $file): bool => $file instanceof File)
            ->keyBy(fn (File $file): int => (int) $file->id);

        if ($files->isEmpty()) {
            return [];
        }

        if (! in_array($type, ['love', 'like', 'funny'], true)) {
            return $this->setManyIndividually($files, $user, $type, $options);
        }

        [$bulkFiles, $fallbackFiles] = $files->partition(
            fn (File $file): bool => ! $file->auto_blacklisted
                && $file->blacklisted_at === null
                && (int) $file->previewed_count < FilePreviewService::FEED_REMOVED_PREVIEW_COUNT
        );
        $queueLibrarySync = $options['queueLibrarySync'] ?? true;
        $results = [];
        $changedFileIds = [];

        foreach ($this->setManyIndividually($fallbackFiles, $user, $type, [
            ...$options,
            'queueLibrarySync' => false,
        ]) as $fileId => $result) {
            $results[$fileId] = $result;
            if ($result['changed']) {
                $changedFileIds[] = (int) $fileId;
            }
        }

        $bulkResult = $this->setManyPositiveWithoutRecovery($bulkFiles, $user, $type, $options);
        app(ContainerBlacklistService::class)->queueEvaluationForFiles(
            $bulkResult['changed_file_ids'],
            (int) $user->id,
        );

        foreach ($bulkResult['results'] as $fileId => $result) {
            $results[$fileId] = $result;
        }
        $changedFileIds = array_values(array_unique([
            ...$changedFileIds,
            ...$bulkResult['changed_file_ids'],
        ]));

        if ($queueLibrarySync && $changedFileIds !== []) {
            app(LibraryIndexSyncDispatcher::class)->filesAndReactions($changedFileIds);
        }

        return $results;
    }

    /**
     * @param  Collection<int, File>  $files
     * @param  array<string, mixed>  $options
     * @return array<int, array{reaction: array{type: string}|null, reacted_at: string|null, changed: bool}>
     */
    private function setManyIndividually(Collection $files, User $user, string $type, array $options): array
    {
        $results = [];
        $reactions = app(FileReactionService::class);

        foreach ($files as $file) {
            $results[(int) $file->id] = $reactions->set($file, $user, $type, $options);
        }

        return $results;
    }

    /**
     * @param  Collection<int, File>  $files
     * @param  array<string, mixed>  $options
     * @return array{results: array<int, array{reaction: array{type: string}|null, reacted_at: string|null, changed: bool}>, changed_file_ids: list<int>}
     */
    private function setManyPositiveWithoutRecovery(Collection $files, User $user, string $type, array $options): array
    {
        if ($files->isEmpty()) {
            return [
                'changed_file_ids' => [],
                'results' => [],
            ];
        }

        $queueDownload = $options['queueDownload'] ?? true;
        $forceDownload = $options['forceDownload'] ?? false;
        $downloadRuntimeContext = $this->normalizeDownloadRuntimeContext(
            $user,
            $options['downloadRuntimeContext'] ?? [],
        );
        $fileIds = $files
            ->keys()
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->values()
            ->all();
        $existingReactions = Reaction::query()
            ->where('user_id', $user->id)
            ->whereIn('file_id', $fileIds)
            ->get()
            ->keyBy(fn (Reaction $reaction): int => (int) $reaction->file_id);
        $counts = $this->reactionCountsForFiles($fileIds, $type, $existingReactions);
        $now = now();
        $rows = [];
        $results = [];
        $changedFileIds = [];
        $metrics = app(MetricsService::class);
        $classifier = app(FileMetricClassifier::class);
        $metricDeltas = [];
        $favoriteAddFileIds = [];
        $favoriteRemoveFileIds = [];

        DB::transaction(function () use (
            $classifier,
            $counts,
            $existingReactions,
            $files,
            $metrics,
            $now,
            &$changedFileIds,
            &$favoriteAddFileIds,
            &$favoriteRemoveFileIds,
            &$metricDeltas,
            &$results,
            &$rows,
            $type,
            $user,
        ): void {
            foreach ($files as $file) {
                $fileId = (int) $file->id;
                $existingReaction = $existingReactions->get($fileId);
                $oldType = $existingReaction?->type;
                $changed = $oldType !== $type;

                $results[$fileId] = [
                    'reaction' => ['type' => $type],
                    'reacted_at' => $changed ? $now->toIso8601String() : $existingReaction?->created_at?->toIso8601String(),
                    'changed' => $changed,
                ];

                if (! $changed) {
                    continue;
                }

                $this->accumulatePositiveReactionMetrics(
                    $file,
                    $oldType,
                    $type,
                    $counts,
                    $classifier,
                    $metricDeltas,
                    $favoriteAddFileIds,
                    $favoriteRemoveFileIds,
                );
                $changedFileIds[] = $fileId;
                $rows[] = [
                    'created_at' => $existingReaction?->created_at ?? $now,
                    'file_id' => $fileId,
                    'type' => $type,
                    'updated_at' => $now,
                    'user_id' => (int) $user->id,
                ];
            }

            if ($rows !== []) {
                Reaction::query()->upsert($rows, ['user_id', 'file_id'], ['type', 'updated_at']);
            }

            foreach ($metricDeltas as $key => $delta) {
                $metrics->incrementMetric($key, $delta);
            }

            if ($favoriteAddFileIds !== []) {
                $metrics->incrementContainersForFileIds($favoriteAddFileIds, 'files_favorited', 1);
            }

            if ($favoriteRemoveFileIds !== []) {
                $metrics->incrementContainersForFileIds($favoriteRemoveFileIds, 'files_favorited', -1);
            }
        });

        if ($queueDownload) {
            foreach ($fileIds as $fileId) {
                $this->dispatchDownloadFile($fileId, $forceDownload, $downloadRuntimeContext);
            }
        }

        if ($changedFileIds !== []) {
            app(TabFileService::class)->detachFilesFromUserTabs((int) $user->id, $changedFileIds);
        }

        return [
            'changed_file_ids' => array_values(array_unique($changedFileIds)),
            'results' => $results,
        ];
    }

    /**
     * @param  list<int>  $fileIds
     * @param  Collection<int, Reaction>  $existingReactions
     * @return array{type: array<int, array<string, int>>, total: array<int, int>}
     */
    private function reactionCountsForFiles(array $fileIds, string $type, Collection $existingReactions): array
    {
        $types = array_values(array_unique(array_filter([
            $type,
            ...$existingReactions
                ->pluck('type')
                ->filter(fn (mixed $reactionType): bool => is_string($reactionType) && $reactionType !== '')
                ->all(),
        ])));
        $typeCounts = [];

        if ($types !== []) {
            $rows = Reaction::query()
                ->whereIn('file_id', $fileIds)
                ->whereIn('type', $types)
                ->select('file_id', 'type', DB::raw('COUNT(*) as total'))
                ->groupBy('file_id', 'type')
                ->get();

            foreach ($rows as $row) {
                $typeCounts[(int) $row->file_id][(string) $row->type] = (int) $row->total;
            }
        }

        $totalCounts = Reaction::query()
            ->whereIn('file_id', $fileIds)
            ->select('file_id', DB::raw('COUNT(*) as total'))
            ->groupBy('file_id')
            ->pluck('total', 'file_id')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'total' => $totalCounts,
            'type' => $typeCounts,
        ];
    }

    /**
     * @param  array{type: array<int, array<string, int>>, total: array<int, int>}  $counts
     * @param  array<string, int>  $metricDeltas
     * @param  list<int>  $favoriteAddFileIds
     * @param  list<int>  $favoriteRemoveFileIds
     */
    private function accumulatePositiveReactionMetrics(
        File $file,
        ?string $oldType,
        string $newType,
        array $counts,
        FileMetricClassifier $classifier,
        array &$metricDeltas,
        array &$favoriteAddFileIds,
        array &$favoriteRemoveFileIds,
    ): void {
        $fileId = (int) $file->id;
        $totalBefore = $counts['total'][$fileId] ?? 0;
        $totalAfter = $totalBefore;

        if ($oldType !== null) {
            $totalAfter--;
            $beforeCount = $counts['type'][$fileId][$oldType] ?? 0;
            if ($beforeCount > 0 && $beforeCount - 1 === 0) {
                $this->addMetricDelta($metricDeltas, $this->reactionMetricKey($oldType), -1);
                if ($oldType === 'love') {
                    $favoriteRemoveFileIds[] = $fileId;
                }
            }
        }

        $beforeNewTypeCount = $counts['type'][$fileId][$newType] ?? 0;
        if ($beforeNewTypeCount === 0) {
            $this->addMetricDelta($metricDeltas, $this->reactionMetricKey($newType), 1);
            if ($newType === 'love') {
                $favoriteAddFileIds[] = $fileId;
            }
        }
        $totalAfter++;

        $isNotFound = $classifier->isNotFound($file);
        $beforeUnreacted = $classifier->countsAsUnreactedBacklog(false, $isNotFound, $totalBefore);
        $afterUnreacted = $classifier->countsAsUnreactedBacklog(false, $isNotFound, $totalAfter);
        if ($beforeUnreacted !== $afterUnreacted) {
            $delta = $afterUnreacted ? 1 : -1;
            $this->addMetricDelta($metricDeltas, MetricsService::KEY_FILES_UNREACTED_NOT_BLACKLISTED, $delta);
            $this->addMetricDelta($metricDeltas, $classifier->unreactedPreviewMetricKey($file), $delta);
        }

        if (($totalBefore > 0) !== ($totalAfter > 0)) {
            $delta = $totalAfter > 0 ? 1 : -1;
            $this->addMetricDelta($metricDeltas, MetricsService::KEY_FILES_REACTED, $delta);
            $this->addMetricDelta($metricDeltas, MetricsService::KEY_FILES_REACTED_NOT_BLACKLISTED, $delta);
        }
    }

    /**
     * @param  array<string, int>  $metricDeltas
     */
    private function addMetricDelta(array &$metricDeltas, ?string $key, int $delta): void
    {
        if ($key === null || $delta === 0) {
            return;
        }

        $metricDeltas[$key] = ($metricDeltas[$key] ?? 0) + $delta;
    }

    private function reactionMetricKey(string $type): ?string
    {
        return match ($type) {
            'love' => MetricsService::KEY_REACTIONS_LOVE,
            'like' => MetricsService::KEY_REACTIONS_LIKE,
            'funny' => MetricsService::KEY_REACTIONS_FUNNY,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $downloadRuntimeContext
     */
    private function dispatchDownloadFile(int $fileId, bool $forceDownload, array $downloadRuntimeContext): void
    {
        DownloadFile::dispatch($fileId, $forceDownload, $downloadRuntimeContext)
            ->onConnection($this->asyncQueueConnection())
            ->onQueue('downloads');
    }

    /**
     * @param  array<string, mixed>  $downloadRuntimeContext
     * @return array<string, mixed>
     */
    private function normalizeDownloadRuntimeContext(User $user, array $downloadRuntimeContext): array
    {
        if (! isset($downloadRuntimeContext['user_id'])) {
            $downloadRuntimeContext['user_id'] = (int) $user->id;
        }

        return $downloadRuntimeContext;
    }

    private function asyncQueueConnection(): string
    {
        $connection = (string) config('downloads.queue_connection', config('queue.default', 'database'));
        $normalized = strtolower(trim($connection));

        if ($normalized === '' || $normalized === 'sync') {
            return 'database';
        }

        return $connection;
    }
}
