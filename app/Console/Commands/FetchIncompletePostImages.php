<?php

namespace App\Console\Commands;

use App\Jobs\FetchPostImages;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FetchIncompletePostImages extends Command
{
    protected $signature = 'civitai:fetch-incomplete-posts
                            {--dry-run : Show what would be done without dispatching jobs}
                            {--delay=5 : Delay in seconds between job dispatches}
                            {--limit= : Limit the number of files to process}';

    protected $description = 'Dispatch FetchPostImages jobs for CivitAI files that have incomplete post data';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $delay = (int) $this->option('delay');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        $this->info('Finding CivitAI files with incomplete post data...');

        // Query to find files that meet our criteria
        $query = File::query()
            ->where('source', 'CivitAI')
            ->where('is_blacklisted', false)
            ->whereHas('metadata', function ($metadataQuery) {
                $metadataQuery->whereRaw("JSON_EXTRACT(payload, '$.data.postId') IS NOT NULL");
            })
            ->with(['metadata', 'containers' => function ($containerQuery) {
                $containerQuery->where('type', 'post')->where('source', 'CivitAI');
            }]);

        if ($limit) {
            $query->limit($limit);
        }

        $files = $query->get();

        $this->info("Found {$files->count()} CivitAI files with post IDs to check.");

        $filesToProcess = collect();

        foreach ($files as $file) {
            $shouldProcess = false;
            $reason = '';

            // Get metadata payload
            $metadata = $file->metadata?->payload ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?? [];
            }

            // Check if file has post_id but no container
            $postContainers = $file->containers->where('type', 'post')->where('source', 'CivitAI');
            
            if ($postContainers->isEmpty()) {
                $shouldProcess = true;
                $reason = 'Has post_id but no post container';
            } else {
                // Check if any of the post containers have only one image
                foreach ($postContainers as $container) {
                    $imageCount = $container->files()->where('source', 'CivitAI')->count();
                    if ($imageCount <= 1) {
                        $shouldProcess = true;
                        $reason = "Post container has only {$imageCount} image(s)";
                        break;
                    }
                }
            }

            if ($shouldProcess) {
                $postId = $metadata['data']['postId'] ?? 'unknown';
                $filesToProcess->push([
                    'file' => $file,
                    'reason' => $reason,
                    'post_id' => $postId
                ]);

                if ($dryRun) {
                    $this->line("Would process: File ID {$file->id} (Post ID: {$postId}) - {$reason}");
                }
            }
        }

        if ($filesToProcess->isEmpty()) {
            $this->info('No files found that need processing.');
            return;
        }

        $this->info("Found {$filesToProcess->count()} files to process:");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No jobs will be dispatched');
            return;
        }

        // Confirm before proceeding
        if (!$this->confirm("Do you want to dispatch FetchPostImages jobs for {$filesToProcess->count()} files?")) {
            $this->info('Operation cancelled.');
            return;
        }

        $this->info('Dispatching jobs...');
        $bar = $this->output->createProgressBar($filesToProcess->count());
        $bar->start();

        $delaySeconds = 0;
        $dispatched = 0;

        foreach ($filesToProcess as $item) {
            $file = $item['file'];
            $reason = $item['reason'];
            $postId = $item['post_id'];

            try {
                FetchPostImages::dispatch($file)->delay(now()->addSeconds($delaySeconds));
                $dispatched++;
                $delaySeconds += $delay;

                $this->newLine();
                $this->info("Dispatched job for File ID {$file->id} (Post ID: {$postId}) - {$reason}");
                $bar->advance();
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to dispatch job for File ID {$file->id}: {$e->getMessage()}");
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Successfully dispatched {$dispatched} FetchPostImages jobs.");
        $this->info("Jobs will be processed with {$delay} second delays between each.");
        $this->info("Total estimated processing time: " . ($dispatched * $delay) . " seconds");
    }
}
