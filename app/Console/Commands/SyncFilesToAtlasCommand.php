<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncFilesToAtlasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:sync-to-atlas {--chunk=100 : Number of files to process per chunk} {--file= : Process only the specified file ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync files to atlas disk - check if files exist in atlas, if not move them from local path or flag as not found';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Increase memory limit for large file processing
        ini_set('memory_limit', '512M');

        $this->info('Starting file sync to atlas disk...');

        $fileId = $this->option('file');
        $chunkSize = (int) $this->option('chunk');

        if ($fileId) {
            return $this->processSingleFile($fileId);
        }

        return $this->processAllFiles($chunkSize);
    }

    /**
     * Process a single file by ID.
     */
    private function processSingleFile(int $fileId): int
    {
        $this->info("Processing only file with ID: {$fileId}");

        $file = File::find($fileId);

        if (!$file) {
            $this->error("File with ID {$fileId} not found.");
            return Command::FAILURE;
        }

        $result = $this->syncFileToAtlas($file);

        $this->info("File ID {$fileId}: {$result['status']}");

        return Command::SUCCESS;
    }

    /**
     * Process all files using chunking.
     */
    private function processAllFiles(int $chunkSize): int
    {
        $totalFiles = File::count();

        if ($totalFiles === 0) {
            $this->info('No files found in the database.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalFiles} files to process.");

        $bar = $this->output->createProgressBar($totalFiles);
        $bar->start();

        $skippedCount = 0;
        $movedCount = 0;
        $notFoundCount = 0;
        $errorCount = 0;

        // Use chunking to avoid memory issues
        File::chunkById($chunkSize, function ($files) use ($bar, &$skippedCount, &$movedCount, &$notFoundCount, &$errorCount) {
            foreach ($files as $file) {
                try {
                    $result = $this->syncFileToAtlas($file);

                    switch ($result['action']) {
                        case 'skipped':
                            $skippedCount++;
                            break;
                        case 'moved':
                            $movedCount++;
                            break;
                        case 'not_found':
                            $notFoundCount++;
                            break;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Error processing file ID {$file->id}: " . $e->getMessage());
                    Log::error("SyncFilesToAtlas error for file ID {$file->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info('File sync to atlas completed:');
        $this->info("- Total files: {$totalFiles}");
        $this->info("- Skipped (already in atlas): {$skippedCount}");
        $this->info("- Moved to atlas: {$movedCount}");
        $this->info("- Flagged as not found: {$notFoundCount}");
        $this->info("- Errors: {$errorCount}");

        return Command::SUCCESS;
    }

    /**
     * Sync a single file to atlas disk according to the requirements.
     */
    private function syncFileToAtlas(File $file): array
    {
        $path = $file->path;

        // Skip files without a path
        if (empty($path)) {
            return [
                'action' => 'skipped',
                'status' => 'No path specified',
            ];
        }

        // Step 1: Check if Storage::disk('atlas')->exists($file->path)
        if (Storage::disk('atlas')->exists($path)) {
            // If it does, skip
            return [
                'action' => 'skipped',
                'status' => 'Already exists in atlas disk',
            ];
        }

        // Step 2: If it doesn't, check if $file->path exists
        if (file_exists($path)) {
            // Step 3: If it does, move it to atlas disk and update the File instance
            try {
                // Read the file content
                $fileContent = file_get_contents($path);

                if ($fileContent === false) {
                    throw new \Exception("Failed to read file content from: {$path}");
                }

                // Store the file in atlas disk
                Storage::disk('atlas')->put($path, $fileContent);

                // Verify the file was stored successfully
                if (!Storage::disk('atlas')->exists($path)) {
                    throw new \Exception("File was not successfully stored in atlas disk");
                }

                // Update the File instance - mark as not not_found (i.e., found)
                $file->not_found = false;
                $file->save();

                // Optionally remove the original file (uncomment if needed)
                // unlink($path);

                return [
                    'action' => 'moved',
                    'status' => 'Moved to atlas disk successfully',
                ];
            } catch (\Exception $e) {
                Log::error("Failed to move file to atlas disk. File ID: {$file->id}, Path: {$path}, Error: " . $e->getMessage());
                throw $e;
            }
        } else {
            // Step 4: If it doesn't, flag as not found
            $file->not_found = true;
            $file->save();

            return [
                'action' => 'not_found',
                'status' => 'File not found, flagged as not_found',
            ];
        }
    }
}
