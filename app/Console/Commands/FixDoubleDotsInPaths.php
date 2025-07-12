<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FixDoubleDotsInPaths extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:fix-double-dots {--file= : Process only the specified file ID} {--chunk=100 : Number of files to process per chunk} {--dry-run : Show what would be changed without making actual changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix double dots in file paths by renaming files and updating database records';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for large file processing
        ini_set('memory_limit', '1024M');

        $this->info('Fixing double dots in file paths...');

        $fileId = $this->option('file');
        $chunkSize = (int) $this->option('chunk');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE: No actual changes will be made');
        }

        if ($fileId) {
            $this->info("Processing only file with ID: {$fileId}");
            $file = File::find($fileId);

            if (!$file) {
                $this->error("File with ID {$fileId} not found.");
                return Command::FAILURE;
            }

            // Process single file
            $this->processSingleFile($file, $dryRun);

            $this->info("Double dots fix completed for file ID: {$fileId}");
            return Command::SUCCESS;
        }

        // Find files with double dots in their paths
        $query = File::where('path', 'like', '%..%');
        $totalFiles = $query->count();

        if ($totalFiles === 0) {
            $this->info('No files found with double dots in their paths.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} files with double dots in their paths.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        // Use chunking to avoid memory issues
        $query->chunkById($chunkSize, function ($files) use ($bar, &$successCount, &$errorCount, &$skippedCount, $dryRun) {
            foreach ($files as $file) {
                try {
                    $result = $this->fixDoubleDotsInPath($file, $dryRun);

                    if ($result['success']) {
                        $successCount++;
                    } elseif ($result['skipped']) {
                        $skippedCount++;
                    } else {
                        $errorCount++;
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

        $this->info('Double dots fix completed:');
        $this->info("- Total files processed: {$totalFiles}");
        $this->info("- Successfully fixed: {$successCount}");
        $this->info("- Skipped (no double dots or file not found): {$skippedCount}");
        $this->info("- Errors: {$errorCount}");

        return Command::SUCCESS;
    }

    /**
     * Process a single file for double dots fix.
     */
    private function processSingleFile(File $file, bool $dryRun): void
    {
        $result = $this->fixDoubleDotsInPath($file, $dryRun);

        if ($result['success']) {
            $message = "File ID {$file->id}: Successfully fixed - {$result['old_path']} -> {$result['new_path']}";
            if (isset($result['type'])) {
                $message .= " ({$result['type']})";
            }
            $this->info($message);
        } elseif ($result['skipped']) {
            $this->info("File ID {$file->id}: Skipped - {$result['reason']}");
        } else {
            $this->error("File ID {$file->id}: Failed - {$result['error']}");
        }
    }

    /**
     * Fix double dots in a file path and update the database record.
     */
    private function fixDoubleDotsInPath(File $file, bool $dryRun = false): array
    {
        $originalPath = $file->path;

        if (!$originalPath) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'No path specified',
            ];
        }

        // Check if path contains double dots
        if (!str_contains($originalPath, '..')) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'No double dots found in path',
            ];
        }

        // Create the corrected path by removing double dots
        $correctedPath = str_replace('..', '.', $originalPath);

        // Check if the original file exists
        $originalExists = false;
        if (filter_var($originalPath, FILTER_VALIDATE_URL)) {
            // For URLs, we assume they exist
            $originalExists = true;
        } else {
            // For local files, check if they exist
            $originalExists = Storage::disk('atlas')->exists($originalPath) || file_exists($originalPath);
        }

        if (!$originalExists) {
            return [
                'success' => false,
                'skipped' => true,
                'reason' => 'Original file does not exist',
            ];
        }

        // For URLs, we can only update the database record
        if (filter_var($originalPath, FILTER_VALIDATE_URL)) {
            if (!$dryRun) {
                $file->path = $correctedPath;
                $file->ext = pathinfo($correctedPath, PATHINFO_EXTENSION);
                $file->save();
            }

            return [
                'success' => true,
                'old_path' => $originalPath,
                'new_path' => $correctedPath,
                'type' => 'URL - database only',
            ];
        }

        // For local files, rename the physical file and update the database
        try {
            if (!$dryRun) {
                // Try to rename using Storage disk first
                if (Storage::disk('atlas')->exists($originalPath)) {
                    Storage::disk('atlas')->move($originalPath, $correctedPath);
                } elseif (file_exists($originalPath)) {
                    // Fallback to direct file system operation
                    if (!rename($originalPath, $correctedPath)) {
                        throw new \Exception("Failed to rename file from {$originalPath} to {$correctedPath}");
                    }
                }

                // Update the database record
                $file->path = $correctedPath;
                $file->ext = pathinfo($correctedPath, PATHINFO_EXTENSION);
                $file->save();
            }

            return [
                'success' => true,
                'old_path' => $originalPath,
                'new_path' => $correctedPath,
                'type' => 'Local file - renamed and database updated',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'skipped' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
