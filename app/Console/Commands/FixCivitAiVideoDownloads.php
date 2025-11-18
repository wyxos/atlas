<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\CivitAiImages;
use Illuminate\Console\Command;

class FixCivitAiVideoDownloads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:fix-civitai-video-downloads
                            {--limit= : Limit the number of files to process}
                            {--dry-run : Show what would be fixed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan and fix CivitAI video files that were downloaded with incorrect extensions (e.g., .webp instead of .mp4)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = $this->option('limit');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('Scanning for affected files...');

        $query = File::query()
            ->where('source', 'CivitAI')
            ->where('downloaded', true)
            ->whereNotNull('path')
            ->whereNotNull('listing_metadata')
            ->whereRaw('JSON_UNQUOTE(JSON_EXTRACT(listing_metadata, ?)) = ?', ['$.type', 'video'])
            ->where(function ($q) {
                $q->where('path', 'LIKE', '%.webp')
                    ->orWhere('path', 'LIKE', '%.bin')
                    ->orWhere('ext', 'webp')
                    ->orWhere('ext', 'bin');
            });

        $filesCount = $query->count();

        if ($limit) {
            $query->limit((int) $limit);
            $filesCount = min($filesCount, (int) $limit);
        }
        $this->info("Found {$filesCount} file(s) to process");

        if ($filesCount === 0) {
            $this->warn('No files to process');

            return Command::SUCCESS;
        }

        $service = app(CivitAiImages::class);
        $fixedCount = 0;
        $failedCount = 0;
        $bar = $this->output->createProgressBar($filesCount);
        $bar->start();

        $query->chunk(100, function ($files) use (&$fixedCount, &$failedCount, $service, $dryRun, $bar) {
            foreach ($files as $file) {
                if ($dryRun) {
                    $this->line("Would fix: File ID {$file->id} - {$file->path}");
                } else {
                    if ($service->fixDownload($file)) {
                        $fixedCount++;
                    } else {
                        $failedCount++;
                    }
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would process {$filesCount} file(s)");
        } else {
            $this->info("Fixed {$fixedCount} file(s)");
            if ($failedCount > 0) {
                $this->warn("Failed to fix {$failedCount} file(s)");
            }
        }

        return Command::SUCCESS;
    }
}
