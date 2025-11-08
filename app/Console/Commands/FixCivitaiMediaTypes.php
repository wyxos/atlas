<?php

namespace App\Console\Commands;

use App\Jobs\DownloadFile;
use App\Models\File;
use Illuminate\Console\Command;

class FixCivitaiMediaTypes extends Command
{
    protected $signature = 'media:fix-civitai-types {--dry-run : Report mismatches without dispatching jobs}';

    protected $description = 'Re-trigger downloads for CivitAI files stored as webp to ensure correct video files are downloaded.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Target all downloaded CivitAI files that are webp
        $query = File::query()
            ->where('source', 'CivitAI')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->where('mime_type', 'image/webp')
            ->select(['id', 'filename', 'referrer_url', 'url', 'thumbnail_url']);

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No CivitAI files matching criteria found (downloaded files with webp mime type).');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dispatched = 0;

        $query->chunkById(500, function ($files) use ($dryRun, &$dispatched, $bar) {
            foreach ($files as $file) {
                if ($dryRun) {
                    $this->line("Would re-trigger download for file {$file->id} ({$file->filename}) - referrer: {$file->referrer_url}");
                    $bar->advance();

                    continue;
                }

                DownloadFile::dispatch($file);
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete.');
        } else {
            $this->info("Dispatched {$dispatched} download job(s).");
        }

        return self::SUCCESS;
    }
}
