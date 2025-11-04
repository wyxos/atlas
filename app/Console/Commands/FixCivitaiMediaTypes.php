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

        $query = File::query()
            ->where('source', 'CivitAI')
            ->where('url', 'like', '%.mp4%')
            ->whereNotNull('path')
            ->select(['id', 'filename']);

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No CivetAI files with mp4 URLs found.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dispatched = 0;

        $query->chunkById(500, function ($files) use ($dryRun, &$dispatched, $bar) {
            foreach ($files as $file) {
                if ($dryRun) {
                    $this->line("Would dispatch job for file {$file->id} ({$file->filename})");
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

