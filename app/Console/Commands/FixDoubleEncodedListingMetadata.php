<?php

namespace App\Console\Commands;

use App\Jobs\FixDoubleEncodedListingMetadataJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixDoubleEncodedListingMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:fix-double-encoded-metadata
                            {--limit= : Limit the number of files to process}
                            {--queue= : Queue name to dispatch jobs to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to fix double-encoded listing metadata (stored as JSON strings instead of proper JSON)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = $this->option('limit');
        $queueName = $this->option('queue');

        $this->info('Finding files with double-encoded listing metadata...');

        // Query files where listing_metadata is not null and stored as a string (double-encoded)
        // In MySQL JSON columns, we need to use JSON_TYPE to check if it's stored as a string
        $query = DB::table('files')
            ->whereNotNull('listing_metadata')
            ->whereRaw("JSON_TYPE(listing_metadata) = 'STRING'")
            ->orderBy('id')
            ->select('id');

        if ($limit) {
            $query->limit((int) $limit);
        }

        $filesCount = $query->count();

        if ($filesCount === 0) {
            $this->info('No files with double-encoded metadata found!');

            return Command::SUCCESS;
        }

        $this->info("Found {$filesCount} file(s) with double-encoded metadata");
        $this->newLine();

        if (! $this->confirm('Do you want to dispatch jobs to fix these files?', true)) {
            $this->warn('Operation cancelled');

            return Command::SUCCESS;
        }

        $dispatchedCount = 0;
        $bar = $this->output->createProgressBar($filesCount);
        $bar->start();

        $query->chunk(500, function ($files) use (&$dispatchedCount, $queueName, $bar) {
            foreach ($files as $file) {
                $job = new FixDoubleEncodedListingMetadataJob($file->id);

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
