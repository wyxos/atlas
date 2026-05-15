<?php

namespace App\Console\Commands;

use App\Enums\BlacklistPreviewedCountMode;
use App\Models\File;
use App\Models\ModerationRule;
use App\Services\FilePreviewService;
use App\Services\Library\LibraryIndexSyncDispatcher;
use App\Services\MetricsService;
use App\Services\Moderation\FilePromptResolver;
use App\Services\Moderation\Moderator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MarkFeedRemovedRuleMatches extends Command
{
    protected $signature = 'atlas:mark-feed-removed-rule-matches
        {--chunk=500 : Number of blacklisted file rows to scan per chunk}
        {--start-id=0 : Resume scanning after this file ID}
        {--max-files=0 : Max candidate files to scan (0 = no limit)}
        {--dry-run : Report aggregate matches without mutating rows}
        {--skip-index-sync : Update rows without queueing library index sync jobs}';

    protected $description = 'Mark already-blacklisted files as feed-removed when they match feed-removal moderation rules';

    public function __construct(
        private readonly FilePromptResolver $promptResolver,
        private readonly MetricsService $metricsService,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $startId = max(0, (int) $this->option('start-id'));
        $maxFiles = max(0, (int) $this->option('max-files'));
        $dryRun = (bool) $this->option('dry-run');
        $skipIndexSync = (bool) $this->option('skip-index-sync');
        $rules = $this->feedRemovalRules();

        $this->line('Feed-removal moderation backfill:');
        $this->line('- active feed-removal rules: '.$rules->count());
        $this->line('- chunk size: '.$chunk);
        $this->line('- start id: '.$startId);
        $this->line('- max files: '.($maxFiles > 0 ? (string) $maxFiles : 'all'));
        $this->line('- mode: '.($dryRun ? 'dry-run' : 'apply'));

        if ($rules->isEmpty()) {
            $this->warn('No active feed-removal rules were found. No rows were changed.');

            return self::SUCCESS;
        }

        $stats = [
            'scanned' => 0,
            'skipped_no_prompt' => 0,
            'matched' => 0,
            'updated' => 0,
        ];
        $afterId = $startId;

        while ($maxFiles === 0 || $stats['scanned'] < $maxFiles) {
            $limit = $maxFiles > 0
                ? min($chunk, $maxFiles - $stats['scanned'])
                : $chunk;

            /** @var Collection<int, File> $files */
            $files = $this->candidateQuery($afterId)
                ->orderBy('id')
                ->limit($limit)
                ->get();

            if ($files->isEmpty()) {
                break;
            }

            $afterId = (int) $files->max('id');
            $stats['scanned'] += $files->count();
            $matchedIds = $this->matchingFileIds($files, $rules, $stats);
            $stats['matched'] += count($matchedIds);

            if (! $dryRun && $matchedIds !== []) {
                $stats['updated'] += $this->markFeedRemoved($matchedIds, $skipIndexSync);
            }
        }

        if ($dryRun) {
            $this->warn('Dry run only. No rows were changed.');
        }

        $this->line('Scanned rows: '.$stats['scanned']);
        $this->line('Skipped rows without prompt: '.$stats['skipped_no_prompt']);
        $this->line('Matched rows: '.$stats['matched']);
        $this->line('Updated rows: '.$stats['updated']);
        $this->info('Feed-removal moderation backfill complete.');

        return self::SUCCESS;
    }

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
}
