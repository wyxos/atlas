<?php

namespace App\Console\Commands;

use App\Jobs\DeleteM3uFilesJob;
use App\Models\Cover;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class DeleteM3uFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:delete-m3u {--preview : Show files that would be deleted without actually deleting them} {--chunk=100 : Number of files to process per chunk} {--force : Skip confirmation prompt} {--batch : Use queue job for batch processing}';

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

        // Use batch processing if --batch flag is specified
        if ($this->option('batch')) {
            return $this->handleBatchDeletion($totalFiles);
        }

        // Handle per-file confirmation and deletion
        return $this->handlePerFileDeletion($query, $totalFiles);
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

    /**
     * Handle batch deletion using queue job.
     */
    private function handleBatchDeletion(int $totalFiles): int
    {
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
     * Handle per-file deletion with confirmation.
     */
    private function handlePerFileDeletion($query, int $totalFiles): int
    {
        $this->info('Processing files individually with confirmation...');
        $this->line('');

        $deletedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Process files one by one
        $query->chunk(1, function ($files) use (&$deletedCount, &$skippedCount, &$errorCount) {
            foreach ($files as $file) {
                // Display file information
                $this->line("File ID: {$file->id}");
                $this->line("Filename: " . ($file->filename ?? 'N/A'));
                $this->line("Path: " . ($file->path ?? 'N/A'));
                $this->line("Size: " . ($file->size ? number_format($file->size) . ' bytes' : 'N/A'));
                $this->line("Mime Type: " . ($file->mime_type ?? 'N/A'));
                $this->line('');

                // Ask for confirmation unless force flag is used
                if ($this->option('force') || $this->confirm('Delete this file?', false)) {
                    try {
                        $this->deleteFile($file);
                        $this->info('✓ File deleted successfully.');
                        $deletedCount++;
                    } catch (\Exception $e) {
                        $this->error('✗ Failed to delete file: ' . $e->getMessage());
                        $errorCount++;
                    }
                } else {
                    $this->info('- File skipped.');
                    $skippedCount++;
                }

                $this->line('');
            }
        });

        // Show summary
        $this->info('Deletion Summary:');
        $this->info("✓ Deleted: {$deletedCount} files");
        $this->info("- Skipped: {$skippedCount} files");
        if ($errorCount > 0) {
            $this->error("✗ Errors: {$errorCount} files");
        }

        return Command::SUCCESS;
    }

    /**
     * Delete a single file from disk and database.
     */
    private function deleteFile(File $file): void
    {
        // Delete from disk if path exists
        if ($file->path) {
            try {
                // Check if it's a URL or local file
                if (!filter_var($file->path, FILTER_VALIDATE_URL)) {
                    // Try to delete from atlas disk first
                    if (Storage::disk('atlas')->exists($file->path)) {
                        Storage::disk('atlas')->delete($file->path);
                    } elseif (file_exists($file->path)) {
                        // Try direct file path
                        unlink($file->path);
                    }
                }
            } catch (\Exception $e) {
                // Continue with database deletion even if disk deletion fails
            }
        }

        // Delete related records first (due to foreign key constraints)
        $file->artists()->detach();
        $file->albums()->detach();
        $file->playlists()->detach();

        // Delete covers using a direct query to avoid SQLite parameter binding issues
        // with the orderByRaw clause in the covers() relationship
        Cover::where('coverable_type', File::class)
             ->where('coverable_id', $file->id)
             ->delete();

        // Delete metadata if exists
        if ($file->metadata) {
            $file->metadata->delete();
        }

        // Finally delete the file record
        $file->delete();
    }
}
