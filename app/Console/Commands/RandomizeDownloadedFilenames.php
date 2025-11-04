<?php

namespace App\Console\Commands;

use App\Jobs\RenameDownloadedFile;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RandomizeDownloadedFilenames extends Command
{
    protected $signature = 'files:randomize-downloaded
        {--dry-run : Report what would change without dispatching jobs}
        {--chunk=500 : Number of records to process per chunk}
        {--dispatch-now : Run jobs synchronously instead of queueing}';

    protected $description = 'Randomize filenames for already-downloaded files by dispatching rename jobs.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $dispatchNow = (bool) $this->option('dispatch-now');

        $query = File::query()
            ->where('downloaded', true)
            ->whereNotNull('path')
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No downloaded files with paths found.');

            return self::SUCCESS;
        }

        $this->info("Scanning {$total} downloaded file(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = [
            'eligible' => 0,
            'dispatched' => 0,
            'skipped_random' => 0,
            'missing' => 0,
        ];

        $pattern = '/^[A-Za-z0-9]{40}(\.[A-Za-z0-9]+)?$/';

        $query->chunkById($chunk, function ($files) use ($dryRun, $dispatchNow, $pattern, &$stats, $bar) {
            foreach ($files as $file) {
                if ($file->filename && preg_match($pattern, $file->filename) === 1) {
                    $stats['skipped_random']++;
                    $bar->advance();

                    continue;
                }

                $stats['eligible']++;

                $availableDisks = collect(['atlas_app', 'atlas'])->filter(function (string $disk) use ($file) {
                    return Storage::disk($disk)->exists($file->path);
                })->values();

                if ($availableDisks->isEmpty()) {
                    $stats['missing']++;
                    $this->warn("File {$file->id} missing on all disks ({$file->path})");
                    $bar->advance();

                    continue;
                }

                if ($dryRun) {
                    $diskList = $availableDisks->implode(', ');
                    $currentFilename = $file->filename ?? '[none]';
                    $this->line("Would rename file {$file->id} ({$currentFilename}), disks: {$diskList}");
                    $bar->advance();

                    continue;
                }

                if ($dispatchNow) {
                    RenameDownloadedFile::dispatchSync($file->id);
                } else {
                    RenameDownloadedFile::dispatch($file->id);
                }

                $stats['dispatched']++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Eligible files: {$stats['eligible']}");
        $this->info("Already randomized: {$stats['skipped_random']}");
        $this->info("Missing on disk: {$stats['missing']}");

        if ($dryRun) {
            $this->info('Dry run complete. No jobs dispatched.');
        } else {
            $this->info("Jobs dispatched: {$stats['dispatched']}");
        }

        return self::SUCCESS;
    }
}
