<?php

namespace App\Console\Commands;

use App\Services\Reactions\ReactionBackfiller;
use Illuminate\Console\Command;

class ReactionsBackfill extends Command
{
    protected $signature = 'reactions:backfill
        {--chunk=1000 : Process files in chunks of this size}
        {--dry-run : Perform a dry run without writing changes}
        {--user= : User ID to attribute reactions to (defaults to first user)}
        {--limit= : Optional max number of files to process}';

    protected $description = 'Backfill per-user reactions from legacy File reaction columns with progress and dry-run support';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');
        $userId = $this->option('user');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('== Reactions Backfill ==');
        $this->line('Options: chunk='.$chunk.' dry-run='.(int) $dryRun.' user='.(string) ($userId ?: 'first').($limit ? ' limit='.$limit : ''));

        $stats = app(ReactionBackfiller::class)->runWithOptions(
            chunk: $chunk,
            dryRun: $dryRun,
            userId: $userId ? (int) $userId : null,
            limit: $limit,
            output: $this->output,
        );

        $this->newLine();
        $this->info(sprintf('Summary: candidates=%d processed=%d created=%d updated=%d', $stats['candidates'], $stats['processed'], $stats['created'], $stats['updated']));

        return self::SUCCESS;
    }
}
