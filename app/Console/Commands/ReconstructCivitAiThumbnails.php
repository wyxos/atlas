<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ReconstructCivitAiThumbnails extends Command
{
    private const CivitAiToken = 'xG1nkqKTMzGDvpLrqFT7WA';

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'civitai:reconstruct-thumbnails
        {--dry-run : Preview the changes without saving them}
        {--chunk=500 : Number of records to process per chunk}';

    /**
     * The console command description.
     */
    protected $description = 'Rebuild CivitAI thumbnail URLs from listing metadata.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $baseQuery = File::query()
            ->where('source', CivitAiImages::SOURCE)
            ->where(function ($query) {
                $query->where('mime_type', 'like', 'video/%')
                    ->orWhere('listing_metadata->type', 'Video');
            })
            ->select(['id', 'source_id', 'url', 'thumbnail_url', 'listing_metadata', 'mime_type', 'not_found']);

        $total = (clone $baseQuery)->count();

        if ($total === 0) {
            $this->info('No CivitAI files found.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d CivitAI files%s...',
            $total,
            $dryRun ? ' (dry run)' : ''
        ));

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $issues = [];

        $baseQuery->orderBy('id')->chunkById($chunkSize, function (Collection $files) use (
            $dryRun,
            $progressBar,
            &$updated,
            &$unchanged,
            &$skipped,
            &$issues
        ) {
            foreach ($files as $file) {


                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        $this->info(sprintf('Updated: %d', $updated));
        $this->info(sprintf('Unchanged: %d', $unchanged));
        $this->info(sprintf('Skipped: %d', $skipped));

        if ($dryRun) {
            $this->comment('Dry run complete. No changes were written to the database.');
        }

        if (! empty($issues)) {
            $this->warn('Files that could not be processed:');
            foreach ($issues as $message) {
                $this->line(' - '.$message);
            }
        }

        return self::SUCCESS;
    }
}
