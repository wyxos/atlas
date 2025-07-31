<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckFilesExistence extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:check-existence {--file= : Process only the specified file ID} {--chunk=100 : Number of files to process per chunk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if files exist in the filesystem and flag not_found if they do not';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for large file processing
        ini_set('memory_limit', '512M');

        $this->info('Checking file existence...');

        $fileId = $this->option('file');
        $chunkSize = (int) $this->option('chunk');

        if ($fileId) {
            $this->info("Processing only file with ID: {$fileId}");
            $file = File::find($fileId);

            if (! $file) {
                $this->error("File with ID {$fileId} not found.");

                return Command::FAILURE;
            }

            // Process single file
            $this->processSingleFile($file);

            $this->info("File existence check completed for file ID: {$fileId}");

            return Command::SUCCESS;
        }

        // Process all files using chunking
        $totalFiles = File::count();

        if ($totalFiles === 0) {
            $this->info('No files found in the database.');

            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} files to check.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $notFoundCount = 0;
        $foundCount = 0;
        $errorCount = 0;

        // Use chunking to avoid memory issues
        File::chunkById($chunkSize, function ($files) use ($bar, &$notFoundCount, &$foundCount, &$errorCount) {
            foreach ($files as $file) {
                try {
                    $result = $this->checkFileExistence($file);

                    if ($result['exists']) {
                        $foundCount++;
                    } else {
                        $notFoundCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Error processing file ID {$file->id}: ".$e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('File existence check completed:');
        $this->info("- Total files: {$totalFiles}");
        $this->info("- Files found: {$foundCount}");
        $this->info("- Files not found: {$notFoundCount}");
        $this->info("- Errors: {$errorCount}");

        return Command::SUCCESS;
    }

    /**
     * Process a single file for existence check.
     */
    private function processSingleFile(File $file): void
    {
        $result = $this->checkFileExistence($file);

        $status = $result['exists'] ? 'found' : 'not found';
        $this->info("File ID {$file->id}: {$status}");
    }

    /**
     * Check if a file exists and update its not_found flag.
     */
    private function checkFileExistence(File $file): array
    {
        $path = $file->path;
        $exists = false;

        if ($path) {
            // Check if the path is a URL
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                // For URLs, we assume they exist (we could add a HTTP check here if needed)
                $exists = true;
            } else {
                // For local files, check if they exist in the filesystem
                $exists = Storage::disk('atlas')->exists($path) || file_exists($path);

                // If file doesn't exist, check for double-dot version
                if (!$exists) {
                    $exists = $this->checkAndFixDoubleDotsFile($path);
                }
            }
        }

        // Update the not_found flag
        $file->not_found = ! $exists;
        $file->save();

        return [
            'exists' => $exists,
            'path' => $path,
        ];
    }

    /**
     * Check for double-dot version of file and rename it if found.
     */
    private function checkAndFixDoubleDotsFile(string $path): bool
    {
        // Get file extension and base path
        $pathInfo = pathinfo($path);
        $directory = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';
        $extension = $pathInfo['extension'] ?? '';

        // Create double-dot version path
        $doubleDotsPath = $directory . DIRECTORY_SEPARATOR . $filename . '..' . $extension;

        // Check if double-dot version exists
        $doubleDotsExists = Storage::disk('atlas')->exists($doubleDotsPath) || file_exists($doubleDotsPath);

        if ($doubleDotsExists) {
            try {
                // Try to rename using Storage disk first
                if (Storage::disk('atlas')->exists($doubleDotsPath)) {
                    Storage::disk('atlas')->move($doubleDotsPath, $path);
                } else {
                    // Fallback to direct file system rename
                    rename($doubleDotsPath, $path);
                }

                return true;
            } catch (\Exception $e) {
                // Return false on error
                return false;
            }
        }

        return false;
    }
}
