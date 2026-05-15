<?php

declare(strict_types=1);

namespace App\Services\Moderation;

use App\Enums\BlacklistPreviewedCountMode;
use App\Enums\ModerationFeedRemovalRunStatus;
use App\Models\File;
use App\Models\ModerationFeedRemovalRun;
use App\Models\ModerationFeedRemovalRunFile;
use App\Models\ModerationRule;
use App\Services\FilePreviewService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Services\MetricsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FeedRemovalBackfillService
{
    public const DEFAULT_CHUNK_SIZE = 500;

    public const MAX_CHUNK_SIZE = 5000;

    public function __construct(
        private readonly FilePromptResolver $promptResolver,
        private readonly MetricsService $metricsService,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {}

    /**
     * @return array{active_rule_count:int,rules_hash:string,scanned:int,skipped_no_prompt:int,matched:int,updated:int,last_file_id:int}
     */
    public function scan(
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $startId = 0,
        int $maxFiles = 0,
        bool $apply = false,
        bool $skipIndexSync = false,
        ?callable $onMatchedIds = null,
        ?callable $onProgress = null,
    ): array {
        $chunkSize = $this->normalizeChunkSize($chunkSize);
        $startId = max(0, $startId);
        $maxFiles = max(0, $maxFiles);
        $rules = $this->feedRemovalRules();
        $stats = $this->emptyStats($rules);
        $afterId = $startId;

        if ($rules->isEmpty()) {
            return $stats;
        }

        while ($maxFiles === 0 || $stats['scanned'] < $maxFiles) {
            $limit = $maxFiles > 0
                ? min($chunkSize, $maxFiles - $stats['scanned'])
                : $chunkSize;

            /** @var Collection<int, File> $files */
            $files = $this->candidateQuery($afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            if ($files->isEmpty()) {
                break;
            }

            $afterId = (int) $files->max('id');
            $stats['last_file_id'] = $afterId;
            $stats['scanned'] += $files->count();
            $matchedIds = $this->matchingFileIds($files, $rules, $stats);
            $stats['matched'] += count($matchedIds);

            if ($matchedIds !== []) {
                if ($onMatchedIds !== null) {
                    $onMatchedIds($matchedIds);
                }

                if ($apply) {
                    $stats['updated'] += $this->markFeedRemoved($matchedIds, $skipIndexSync);
                }
            }

            if ($onProgress !== null) {
                $onProgress($stats);
            }
        }

        return $stats;
    }

    public function previewRun(ModerationFeedRemovalRun $run): ModerationFeedRemovalRun
    {
        try {
            $run->matchedFiles()->delete();
            $run->update([
                'status' => ModerationFeedRemovalRunStatus::PREVIEWING,
                'phase' => 'scanning',
                'started_at' => now(),
                'finished_at' => null,
                'applied_at' => null,
                'active_rule_count' => 0,
                'rules_hash' => null,
                'scanned_count' => 0,
                'skipped_no_prompt_count' => 0,
                'matched_count' => 0,
                'updated_count' => 0,
                'error' => null,
            ]);

            $stats = $this->scan(
                chunkSize: (int) $run->chunk_size,
                onMatchedIds: fn (array $fileIds) => $this->recordMatchedFiles($run, $fileIds),
                onProgress: fn (array $stats) => $this->persistScanStats($run, $stats),
            );

            $run->update([
                'status' => ModerationFeedRemovalRunStatus::PREVIEWED,
                'phase' => 'ready',
                'active_rule_count' => $stats['active_rule_count'],
                'rules_hash' => $stats['rules_hash'],
                'scanned_count' => $stats['scanned'],
                'skipped_no_prompt_count' => $stats['skipped_no_prompt'],
                'matched_count' => $stats['matched'],
                'updated_count' => $stats['updated'],
                'finished_at' => now(),
            ]);

            return $run->fresh();
        } catch (\Throwable $e) {
            report($e);

            return $this->failRun($run, 'Preview failed. Check application logs.');
        }
    }

    public function applyRun(ModerationFeedRemovalRun $run, bool $skipIndexSync = false): ModerationFeedRemovalRun
    {
        if ($run->status !== ModerationFeedRemovalRunStatus::PREVIEWED
            && $run->status !== ModerationFeedRemovalRunStatus::APPLYING
        ) {
            return $run;
        }

        if (! $this->rulesMatchRun($run)) {
            $run->update([
                'status' => ModerationFeedRemovalRunStatus::STALE,
                'phase' => 'rules_changed',
                'finished_at' => now(),
                'error' => null,
            ]);

            return $run->fresh();
        }

        try {
            $run->update([
                'status' => ModerationFeedRemovalRunStatus::APPLYING,
                'phase' => 'applying',
                'error' => null,
            ]);

            $updated = 0;

            ModerationFeedRemovalRunFile::query()
                ->where('moderation_feed_removal_run_id', $run->id)
                ->orderBy('id')
                ->chunkById($this->normalizeChunkSize((int) $run->chunk_size), function (Collection $rows) use ($run, &$updated, $skipIndexSync): void {
                    $fileIds = $rows
                        ->pluck('file_id')
                        ->map(fn ($fileId): int => (int) $fileId)
                        ->values()
                        ->all();

                    $eligibleIds = File::query()
                        ->whereIn('id', $fileIds)
                        ->whereNotNull('blacklisted_at')
                        ->where('previewed_count', '<', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
                        ->pluck('id')
                        ->map(fn ($fileId): int => (int) $fileId)
                        ->all();

                    if ($eligibleIds === []) {
                        return;
                    }

                    $updated += $this->markFeedRemoved($eligibleIds, $skipIndexSync);
                    $run->update([
                        'updated_count' => $updated,
                        'updated_at' => now(),
                    ]);
                });

            $run->update([
                'status' => ModerationFeedRemovalRunStatus::APPLIED,
                'phase' => $updated > 0 ? 'library_sync_queued' : 'completed',
                'updated_count' => $updated,
                'applied_at' => now(),
                'finished_at' => now(),
            ]);

            return $run->fresh();
        } catch (\Throwable $e) {
            report($e);

            return $this->failRun($run, 'Apply failed. Check application logs.');
        }
    }

    public function currentRulesHash(): string
    {
        return $this->hashRules($this->feedRemovalRules());
    }

    public function currentRuleCount(): int
    {
        return $this->feedRemovalRules()->count();
    }

    public function rulesMatchRun(ModerationFeedRemovalRun $run): bool
    {
        return is_string($run->rules_hash)
            && $run->rules_hash !== ''
            && hash_equals($run->rules_hash, $this->currentRulesHash());
    }

    public function normalizeChunkSize(int $chunkSize): int
    {
        return max(1, min(self::MAX_CHUNK_SIZE, $chunkSize));
    }

    /**
     * @return Collection<int, ModerationRule>
     */
    private function feedRemovalRules(): Collection
    {
        return ModerationRule::query()
            ->where('active', true)
            ->where('blacklist_previewed_count_mode', BlacklistPreviewedCountMode::FEED_REMOVED)
            ->orderBy('id')
            ->get();
    }

    private function candidateQuery(int $afterId): Builder
    {
        return File::query()
            ->select(['id', 'listing_metadata', 'detail_metadata', 'previewed_count', 'blacklisted_at'])
            ->with('metadata:id,file_id,payload')
            ->where('id', '>', $afterId)
            ->whereNotNull('blacklisted_at')
            ->where('previewed_count', '<', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT);
    }

    /**
     * @param  Collection<int, File>  $files
     * @param  Collection<int, ModerationRule>  $rules
     * @param  array{active_rule_count:int,rules_hash:string,scanned:int,skipped_no_prompt:int,matched:int,updated:int,last_file_id:int}  $stats
     * @return list<int>
     */
    private function matchingFileIds(Collection $files, Collection $rules, array &$stats): array
    {
        $matchedIds = [];
        $moderator = new Moderator;

        foreach ($files as $file) {
            $prompt = $this->promptResolver->resolve($file);

            if ($prompt === null) {
                $stats['skipped_no_prompt']++;

                continue;
            }

            foreach ($rules as $rule) {
                $moderator->loadRule($rule);

                if ($moderator->check($prompt)) {
                    $matchedIds[] = (int) $file->id;

                    break;
                }
            }
        }

        return $matchedIds;
    }

    /**
     * @param  list<int>  $fileIds
     */
    private function markFeedRemoved(array $fileIds, bool $skipIndexSync): int
    {
        $this->metricsService->applyBlacklistedFeedRemovedMark($fileIds);

        $updated = File::query()
            ->whereIn('id', $fileIds)
            ->whereNotNull('blacklisted_at')
            ->where('previewed_count', '<', FilePreviewService::FEED_REMOVED_PREVIEW_COUNT)
            ->update([
                'previewed_count' => FilePreviewService::FEED_REMOVED_PREVIEW_COUNT,
                'updated_at' => now(),
            ]);

        if ($updated > 0 && ! $skipIndexSync) {
            $this->libraryIndexSyncDispatcher->filesAndReactions($fileIds);
        }

        return $updated;
    }

    /**
     * @param  list<int>  $fileIds
     */
    private function recordMatchedFiles(ModerationFeedRemovalRun $run, array $fileIds): void
    {
        $now = now();
        $rows = array_map(fn (int $fileId): array => [
            'moderation_feed_removal_run_id' => $run->id,
            'file_id' => $fileId,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fileIds);

        ModerationFeedRemovalRunFile::query()->insertOrIgnore($rows);
    }

    /**
     * @param  array{active_rule_count:int,rules_hash:string,scanned:int,skipped_no_prompt:int,matched:int,updated:int,last_file_id:int}  $stats
     */
    private function persistScanStats(ModerationFeedRemovalRun $run, array $stats): void
    {
        $run->update([
            'active_rule_count' => $stats['active_rule_count'],
            'rules_hash' => $stats['rules_hash'],
            'scanned_count' => $stats['scanned'],
            'skipped_no_prompt_count' => $stats['skipped_no_prompt'],
            'matched_count' => $stats['matched'],
            'updated_count' => $stats['updated'],
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  Collection<int, ModerationRule>  $rules
     * @return array{active_rule_count:int,rules_hash:string,scanned:int,skipped_no_prompt:int,matched:int,updated:int,last_file_id:int}
     */
    private function emptyStats(Collection $rules): array
    {
        return [
            'active_rule_count' => $rules->count(),
            'rules_hash' => $this->hashRules($rules),
            'scanned' => 0,
            'skipped_no_prompt' => 0,
            'matched' => 0,
            'updated' => 0,
            'last_file_id' => 0,
        ];
    }

    /**
     * @param  Collection<int, ModerationRule>  $rules
     */
    private function hashRules(Collection $rules): string
    {
        return hash('sha256', json_encode(
            $rules
                ->map(fn (ModerationRule $rule): array => [
                    'id' => (int) $rule->id,
                    'active' => (bool) $rule->active,
                    'blacklist_previewed_count_mode' => $rule->blacklist_previewed_count_mode,
                    'op' => $rule->op,
                    'terms' => $rule->terms,
                    'min' => $rule->min,
                    'options' => $rule->options,
                    'children' => $rule->children,
                ])
                ->values()
                ->all(),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function failRun(ModerationFeedRemovalRun $run, string $message): ModerationFeedRemovalRun
    {
        $run->update([
            'status' => ModerationFeedRemovalRunStatus::FAILED,
            'phase' => 'failed',
            'finished_at' => now(),
            'error' => $message,
        ]);

        return $run->fresh();
    }
}
