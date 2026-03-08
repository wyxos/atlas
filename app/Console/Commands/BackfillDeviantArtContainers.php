<?php

namespace App\Console\Commands;

use App\Jobs\BackfillDeviantArtContainers as BackfillDeviantArtContainersJob;
use Illuminate\Console\Command;

class BackfillDeviantArtContainers extends Command
{
    protected $signature = 'atlas:backfill-deviantart-containers
        {--chunk=500 : Number of matching files to process per queued job}
        {--queue=processing : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}';

    protected $description = 'Queue a backfill that derives DeviantArt post/user containers for existing files';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $queue = trim((string) $this->option('queue'));
        $startId = max(0, (int) $this->option('start-id'));

        if ($queue === '') {
            $queue = 'processing';
        }

        BackfillDeviantArtContainersJob::dispatch($startId, $chunk, $queue);

        $this->info("Queued DeviantArt container backfill from file id > {$startId} with chunk={$chunk} on queue={$queue}.");

        return self::SUCCESS;
    }
}
