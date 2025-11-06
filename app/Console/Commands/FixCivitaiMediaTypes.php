<?php

namespace App\Console\Commands;

use App\Jobs\FixCivitaiMediaType;
use App\Models\File;
use Illuminate\Console\Command;

class FixCivitaiMediaTypes extends Command
{
    protected $signature = 'media:fix-civitai-types {--dry-run : Report mismatches without dispatching jobs}';

    protected $description = 'Queue jobs to correct mislabeled CivetAI media files stored locally with incorrect extensions.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Target files where thumbnail_url contains mp4 and mime_type is webp
        $query = File::query()
            ->where('source', 'CivitAI')
            ->whereNotNull('path')
            ->where('path', '!=', '')
            ->whereNotNull('thumbnail_url')
            ->where(fn($query) => $query->where('thumbnail_url', 'like', '%mp4%')
            ->orWhere('url', 'like', '%mp4%'))
            ->where('mime_type', 'image/webp')
            ->select(['id', 'filename', 'referrer_url']);

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No CivitAI files matching criteria found (thumbnail_url contains mp4, mime_type is webp).');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dispatched = 0;

        $query->chunkById(500, function ($files) use ($dryRun, &$dispatched, $bar) {
            foreach ($files as $file) {
                if ($dryRun) {
                    $this->line("Would dispatch job for file {$file->id} ({$file->filename}) - referrer: {$file->referrer_url}");
                    $bar->advance();

                    continue;
                }

                FixCivitaiMediaType::dispatch($file->id);
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        if ($dryRun) {
            $this->info('Dry run complete.');
        } else {
            $this->info("Dispatched {$dispatched} job(s).");
        }

        return self::SUCCESS;
    }
}
