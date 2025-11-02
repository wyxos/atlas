<?php

namespace App\Console\Commands;

use App\Jobs\ProcessFileListingMetadataJob;
use App\Models\File;
use Illuminate\Console\Command;

class ProcessFileListingMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:process-listing-metadata
                            {--limit= : Limit the number of files to process}
                            {--queue= : Queue name to dispatch jobs to}
                            {--skip-processed : Skip files that already have containers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to process file listing metadata and create container instances for CivitAI posts and users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = $this->option('limit');
        $queueName = $this->option('queue');
        $skipProcessed = $this->option('skip-processed');

        $this->info('Dispatching jobs to process files with listing metadata...');

        if ($skipProcessed) {
            $this->info('Skipping files that already have containers');
        }

        // Query files with listing metadata from CivitAI source
        $query = File::query()
            ->where('source', 'CivitAI')
            ->whereNotNull('listing_metadata')
            ->select('id'); // Only need the ID for dispatching

        // Skip files that already have containers if requested
        if ($skipProcessed) {
            $query->whereDoesntHave('containers');
        }

        if ($limit) {
            $query->limit((int) $limit);
        }

        $filesCount = $query->count();
        $this->info("Found {$filesCount} file(s) to process");

        if ($filesCount === 0) {
            $this->warn('No files to process');

            return Command::SUCCESS;
        }

        $dispatchedCount = 0;
        $bar = $this->output->createProgressBar($filesCount);
        $bar->start();

        $query->chunk(500, function ($files) use (&$dispatchedCount, $queueName, $bar) {
            foreach ($files as $file) {
                $job = new ProcessFileListingMetadataJob($file->id);

                if ($queueName) {
                    $job->onQueue($queueName);
                }

                dispatch($job);
                $dispatchedCount++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Successfully dispatched {$dispatchedCount} job(s)");

        if ($queueName) {
            $this->info("Jobs dispatched to queue: {$queueName}");
        } else {
            $this->info('Jobs dispatched to default queue');
        }

        $this->newLine();
        $this->comment('Monitor job progress with: php artisan queue:work');
        $this->comment('Check failed jobs with: php artisan queue:failed');

        return Command::SUCCESS;
    }
}
