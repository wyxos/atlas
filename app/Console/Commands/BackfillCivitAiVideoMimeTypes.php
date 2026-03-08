<?php

namespace App\Console\Commands;

use App\Jobs\BackfillCivitAiVideoMimeTypes as BackfillCivitAiVideoMimeTypesJob;
use Illuminate\Console\Command;

class BackfillCivitAiVideoMimeTypes extends Command
{
    protected $signature = 'atlas:backfill-civitai-video-mime-types
        {--chunk=500 : Number of matching files to process per queued job}
        {--queue=processing : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}';

    protected $description = 'Queue a backfill that normalizes stale CivitAI MP4 MIME types to video/mp4';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $queue = trim((string) $this->option('queue'));
        $startId = max(0, (int) $this->option('start-id'));

        if ($queue === '') {
            $queue = 'processing';
        }

        BackfillCivitAiVideoMimeTypesJob::dispatch($startId, $chunk, $queue);

        $this->info("Queued CivitAI video MIME backfill from file id > {$startId} with chunk={$chunk} on queue={$queue}.");

        return self::SUCCESS;
    }
}
