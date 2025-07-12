<?php

namespace App\Console\Commands;

use App\Models\Cover;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupDuplicateFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:cleanup-duplicates {--dry-run : Show what would be deleted without actually deleting} {--chunk=100 : Number of files to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete files that have the same hash as existing covers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for large file processing
        ini_set('memory_limit', '512M');

        $isDryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        if ($isDryRun) {
            $this->info('Running in DRY RUN mode - no files will be deleted');
        }

        $this->info('Starting cleanup of duplicate files...');

        // Get total count of files with hashes
        $totalFiles = File::whereNotNull('hash')->count();

        if ($totalFiles === 0) {
            $this->info('No files with hashes found in the database.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} files with hashes to check.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $deletedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        // Use chunking to avoid memory issues
        File::whereNotNull('hash')->chunkById($chunkSize, function ($files) use ($bar, &$deletedCount, &$skippedCount, &$errorCount, $isDryRun) {
            foreach ($files as $file) {
                try {
                    $result = $this->processFile($file, $isDryRun);

                    if ($result['deleted']) {
                        $deletedCount++;
                    } else {
                        $skippedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Error processing file ID {$file->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('Cleanup completed:');
        $this->info("- Total files processed: {$totalFiles}");
        $this->info("- Files deleted: {$deletedCount}");
        $this->info("- Files skipped: {$skippedCount}");
        $this->info("- Errors: {$errorCount}");

        if ($isDryRun && $deletedCount > 0) {
            $this->warn("This was a dry run. Run without --dry-run to actually delete the files.");
        }

        return Command::SUCCESS;
    }

    /**
     * Process a single file to check if it should be deleted.
     */
    private function processFile(File $file, bool $isDryRun): array
    {
        // Skip files without hash
        if (!$file->hash) {
            return ['deleted' => false, 'reason' => 'No hash'];
        }

        // Check if a cover exists with the same hash
        $coverExists = Cover::where('hash', $file->hash)->exists();

        if (!$coverExists) {
            return ['deleted' => false, 'reason' => 'No matching cover'];
        }

        if ($isDryRun) {
            $this->line("Would delete file ID {$file->id}: {$file->path}");
            return ['deleted' => true, 'reason' => 'Dry run'];
        }

        // Delete the physical file if it exists
        $this->deletePhysicalFile($file);

        // Delete the File instance from database
        $file->delete();

        return ['deleted' => true, 'reason' => 'Deleted'];
    }

    /**
     * Delete the physical file from storage.
     */
    private function deletePhysicalFile(File $file): void
    {
        if (!$file->path) {
            return;
        }

        try {
            // Check if the path is a URL (skip deletion for URLs)
            if (filter_var($file->path, FILTER_VALIDATE_URL)) {
                return;
            }

            // Try to delete from atlas disk first
            if (Storage::disk('atlas')->exists($file->path)) {
                Storage::disk('atlas')->delete($file->path);
            } elseif (file_exists($file->path)) {
                // Fallback to direct file deletion
                unlink($file->path);
            }
        } catch (\Exception $e) {
            // Log the error but don't stop the process
            $this->warn("Could not delete physical file for ID {$file->id}: " . $e->getMessage());
        }
    }
}
