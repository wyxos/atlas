<?php

namespace App\Console\Commands;

use App\Jobs\RegenerateVideoPreviewAssets;
use App\Models\File;
use Illuminate\Console\Command;

class RegenerateVideoPreviews extends Command
{
    protected $signature = 'atlas:regenerate-video-previews
        {--chunk=500 : Chunk size for scanning file IDs}
        {--limit=0 : Max number of files to queue (0 = no limit)}
        {--queue=maintenance : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}
        {--dry-run : Print how many would be queued without dispatching}';

    protected $description = 'Queue regeneration jobs for downloaded videos that already have preview assets';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $startId = max(0, (int) $this->option('start-id'));
        $queue = trim((string) $this->option('queue'));
        $dryRun = (bool) $this->option('dry-run');

        if ($queue === '') {
            $queue = 'maintenance';
        }

        $query = File::query()
            ->where('id', '>', $startId)
            ->where('downloaded', true)
            ->whereNotNull('path')
            ->where(function ($q) {
                $q->where('mime_type', 'like', 'video/%')
                    ->orWhere('mime_type', 'application/mp4');
            })
            ->where(function ($q) {
                $q->whereNotNull('preview_path')
                    ->orWhereNotNull('poster_path');
            })
            ->orderBy('id');

        if ($dryRun) {
            $count = (clone $query)->count();
            $this->info("Would queue {$count} video file(s) to queue={$queue}.");

            return self::SUCCESS;
        }

        $queued = 0;

        $query->chunkById($chunk, function ($files) use (&$queued, $limit, $queue) {
            foreach ($files as $file) {
                if ($limit > 0 && $queued >= $limit) {
                    return false;
                }

                RegenerateVideoPreviewAssets::dispatch($file->id)->onQueue($queue);
                $queued += 1;
            }

            return true;
        });

        $this->info("Queued {$queued} video file(s) to queue={$queue}.");

        return self::SUCCESS;
    }
}
