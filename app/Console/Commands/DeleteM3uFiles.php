<?php

namespace App\Console\Commands;

use App\Jobs\DeleteM3uFilesJob;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class DeleteM3uFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:delete-m3u {--preview : Show files that would be deleted without actually deleting them} {--chunk=100 : Number of files to process per chunk} {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan for File records containing m3u and delete them from disk and database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Scanning for M3U files...');

        // Build query to find M3U files
        $query = File::where(function ($q) {
            $q->where('ext', 'like', '%m3u%')
              ->orWhere('mime_type', 'like', '%m3u%')
              ->orWhere('mime_type', 'like', '%mpegurl%')
              ->orWhere('filename', 'like', '%.m3u%');
        });

        $totalFiles = $query->count();

        if ($totalFiles === 0) {
            $this->info('No M3U files found in the database.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} M3U files.");

        // Show preview if requested
        if ($this->option('preview')) {
            $this->showPreview($query);
            return Command::SUCCESS;
        }

        // Show confirmation unless force flag is used
        if (!$this->option('force')) {
            $this->warn("This will permanently delete {$totalFiles} M3U files from both disk and database.");

            if (!$this->confirm('Are you sure you want to proceed?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        // Dispatch the job
        $chunkSize = (int) $this->option('chunk');

        $this->info('Dispatching deletion job to queue...');

        $job = new DeleteM3uFilesJob($chunkSize);
        Queue::push($job);

        $this->info('M3U files deletion job has been queued.');
        $this->info('You can monitor the job progress in the logs or queue dashboard.');
        $this->line('');
        $this->info('To process the queue, run: php artisan queue:work');

        return Command::SUCCESS;
    }

    /**
     * Show preview of files that would be deleted.
     */
    private function showPreview($query): void
    {
        $this->info('Preview of M3U files that would be deleted:');
        $this->line('');

        $headers = ['ID', 'Filename', 'Path', 'Size', 'Mime Type'];
        $rows = [];

        // Get first 20 files for preview
        $files = $query->limit(20)->get();

        foreach ($files as $file) {
            $rows[] = [
                $file->id,
                $file->filename ?? 'N/A',
                $file->path ? (strlen($file->path) > 50 ? substr($file->path, 0, 47) . '...' : $file->path) : 'N/A',
                $file->size ? number_format($file->size) . ' bytes' : 'N/A',
                $file->mime_type ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);

        if ($query->count() > 20) {
            $remaining = $query->count() - 20;
            $this->info("... and {$remaining} more files.");
        }

        $this->line('');
        $this->info('To actually delete these files, run the command without --preview flag.');
    }
}
