<?php

namespace App\Console\Commands;

use App\Jobs\RepairCivitAiImageUrls as RepairCivitAiImageUrlsJob;
use Illuminate\Console\Command;

class RepairCivitAiImageUrls extends Command
{
    protected $signature = 'atlas:repair-civitai-image-urls
        {--chunk=500 : Number of matching files to process per queued job}
        {--queue=processing : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}
        {--max-files=0 : Max number of files to process (0 = no limit)}
        {--dry-run : Scan matching files without mutating records}';

    protected $description = 'Queue a backfill that repairs stale CivitAI width-based image URLs';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $queue = trim((string) $this->option('queue'));
        $startId = max(0, (int) $this->option('start-id'));
        $maxFiles = max(0, (int) $this->option('max-files'));
        $dryRun = (bool) $this->option('dry-run');

        if ($queue === '') {
            $queue = 'processing';
        }

        RepairCivitAiImageUrlsJob::dispatch($startId, $chunk, $queue, $maxFiles, $dryRun);

        $maxFilesSummary = $maxFiles > 0 ? (string) $maxFiles : 'all';
        $mode = $dryRun ? 'dry-run ' : '';

        $this->info(
            "Queued {$mode}CivitAI image URL repair from file id > {$startId} ".
            "with chunk={$chunk}, max-files={$maxFilesSummary}, queue={$queue}."
        );

        return self::SUCCESS;
    }
}
