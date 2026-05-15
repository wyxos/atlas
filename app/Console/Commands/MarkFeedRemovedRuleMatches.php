<?php

namespace App\Console\Commands;

use App\Services\Moderation\FeedRemovalBackfillService;
use Illuminate\Console\Command;

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
        private readonly FeedRemovalBackfillService $backfill,
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
        $activeRuleCount = $this->backfill->currentRuleCount();

        $this->line('Feed-removal moderation backfill:');
        $this->line('- active feed-removal rules: '.$activeRuleCount);
        $this->line('- chunk size: '.$chunk);
        $this->line('- start id: '.$startId);
        $this->line('- max files: '.($maxFiles > 0 ? (string) $maxFiles : 'all'));
        $this->line('- mode: '.($dryRun ? 'dry-run' : 'apply'));

        if ($activeRuleCount === 0) {
            $this->warn('No active feed-removal rules were found. No rows were changed.');

            return self::SUCCESS;
        }

        $stats = $this->backfill->scan(
            chunkSize: $chunk,
            startId: $startId,
            maxFiles: $maxFiles,
            apply: ! $dryRun,
            skipIndexSync: $skipIndexSync,
        );

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
}
