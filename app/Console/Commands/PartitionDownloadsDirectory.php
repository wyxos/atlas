<?php

namespace App\Console\Commands;

use App\Jobs\PartitionDownloadedFile;
use App\Models\File;
use App\Support\PartitionedPathHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PartitionDownloadsDirectory extends Command
{
    protected $signature = 'files:partition-downloads
                            {--dry-run : Show what would be moved without making changes}
                            {--chunk=500 : Number of records to process per chunk}
                            {--limit= : Limit the number of files to process}
                            {--subdir-length=2 : Number of characters to use for subdirectory name}
                            {--queue= : Queue name to dispatch jobs to}
                            {--dispatch-now : Run jobs synchronously instead of queueing}';

    protected $description = 'Partition files in downloads directory into subdirectories to improve filesystem performance';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;
        $subdirLength = max(1, min(4, (int) $this->option('subdir-length')));
        $queueName = $this->option('queue');
        $dispatchNow = (bool) $this->option('dispatch-now');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info("Partitioning downloads directory (subdirectory length: {$subdirLength})...");

        try {
            $query = File::query()
                ->where('downloaded', true)
                ->whereNotNull('path')
                ->where('path', 'LIKE', 'downloads/%')
                ->where('path', 'NOT LIKE', 'downloads/%/%') // Exclude already partitioned files
                ->orderBy('id');

            $total = (clone $query)->count();
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'No connection could be made') || str_contains($e->getMessage(), 'Connection refused')) {
                $this->error('Database connection failed. Please ensure your database server is running.');
                $this->line('Error: '.$e->getMessage());

                return Command::FAILURE;
            }

            throw $e;
        }

        if ($limit) {
            $query->limit($limit);
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('No files found to partition.');

            return Command::SUCCESS;
        }

        $this->info("Found {$total} file(s) to process");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'processed' => 0,
            'dispatched' => 0,
            'skipped' => 0,
            'failed' => 0,
            'missing' => 0,
        ];

        $query->chunkById($chunk, function ($files) use ($dryRun, $subdirLength, $queueName, $dispatchNow, &$stats, $bar) {
            foreach ($files as $file) {
                try {
                    $stats['processed']++;

                    // Check if already partitioned
                    $filename = basename($file->path);
                    if ($filename === '' || $filename === $file->path) {
                        $filename = $file->filename ?? Str::random(40);
                    }
                    $newPath = PartitionedPathHelper::generatePath($filename, $subdirLength);
                    if ($file->path === $newPath) {
                        $stats['skipped']++;
                        $bar->advance();

                        continue;
                    }

                    // Check if file exists on disk
                    $disksWithFile = collect(['atlas_app', 'atlas'])->filter(function (string $disk) use ($file) {
                        return Storage::disk($disk)->exists($file->path);
                    })->values();

                    if ($disksWithFile->isEmpty()) {
                        $stats['missing']++;
                        $bar->advance();

                        continue;
                    }

                    if ($dryRun) {
                        $diskList = $disksWithFile->implode(', ');
                        $this->line("Would partition: File ID {$file->id} from {$file->path} to {$newPath} (disks: {$diskList})");
                        $stats['skipped']++;
                        $bar->advance();

                        continue;
                    }

                    // Dispatch job
                    $job = new PartitionDownloadedFile($file->id, $subdirLength);
                    if ($queueName) {
                        $job->onQueue($queueName);
                    }

                    if ($dispatchNow) {
                        $job->handle();
                    } else {
                        dispatch($job);
                    }

                    $stats['dispatched']++;
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->error("Failed to process file ID {$file->id}: {$e->getMessage()}");
                    $stats['failed']++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed: {$stats['processed']}");
        if ($dryRun) {
            $this->info('Dry run complete. No jobs dispatched.');
        } else {
            $this->info("Jobs dispatched: {$stats['dispatched']}");
            if ($queueName) {
                $this->info("Jobs dispatched to queue: {$queueName}");
            } else {
                $this->info('Jobs dispatched to default queue');
            }
        }
        $this->info("Skipped (already partitioned): {$stats['skipped']}");
        $this->info("Missing: {$stats['missing']}");
        if ($stats['failed'] > 0) {
            $this->warn("Failed: {$stats['failed']}");
        }

        if (! $dryRun && ! $dispatchNow) {
            $this->newLine();
            $this->comment('Monitor job progress with: php artisan queue:work'.($queueName ? " --queue={$queueName}" : ''));
        }

        return Command::SUCCESS;
    }
}
