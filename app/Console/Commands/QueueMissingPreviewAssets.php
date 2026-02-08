<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use Illuminate\Console\Command;

class QueueMissingPreviewAssets extends Command
{
    protected $signature = 'atlas:queue-missing-previews
        {--chunk=500 : Chunk size for scanning file IDs}
        {--limit=0 : Max number of files to queue (0 = no limit)}
        {--queue=processing : Queue name to dispatch jobs to}
        {--dry-run : Print how many would be queued without dispatching}';

    protected $description = 'Queue preview/poster generation jobs for downloaded files missing preview assets';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $chunk = $chunk > 0 ? $chunk : 500;

        $limit = (int) $this->option('limit');
        $limit = $limit > 0 ? $limit : 0;

        $queue = (string) $this->option('queue');
        if ($queue === '') {
            $queue = 'processing';
        }

        $dryRun = (bool) $this->option('dry-run');

        $query = File::query()
            ->where('downloaded', true)
            ->whereNotNull('path')
            ->where(function ($q) {
                $q
                    // Missing thumbnail for images or videos
                    ->whereNull('preview_path')
                    // Missing poster for videos
                    ->orWhere(function ($q) {
                        $q->where('mime_type', 'like', 'video/%')->whereNull('poster_path');
                    });
            })
            ->where(function ($q) {
                // Only queue jobs that can actually produce assets.
                $q->where('mime_type', 'like', 'image/%')->orWhere('mime_type', 'like', 'video/%');
            })
            ->orderBy('id');

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would queue {$count} file(s).");

            return self::SUCCESS;
        }

        $queued = 0;

        $query->chunkById($chunk, function ($files) use (&$queued, $limit, $queue) {
            foreach ($files as $file) {
                if ($limit > 0 && $queued >= $limit) {
                    return false;
                }

                GenerateFilePreviewAssets::dispatch($file->id)->onQueue($queue);
                $queued += 1;
            }

            return true;
        });

        // Horizon/reverb workers may not print this, but it is useful in CLI.
        $this->info("Queued {$queued} file(s) to queue={$queue}.");

        return self::SUCCESS;
    }
}
