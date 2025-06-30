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
    protected $signature = 'files:check-existence {--file= : Process only the specified file ID}';

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
        $this->info('Checking file existence...');

        $fileId = $this->option('file');

        if ($fileId) {
            $this->info("Processing only file with ID: {$fileId}");
            $files = File::where('id', $fileId)->get();

            if ($files->isEmpty()) {
                $this->error("File with ID {$fileId} not found.");
                return Command::FAILURE;
            }
        } else {
            $files = File::all();
        }

        $totalFiles = $files->count();
        $notFoundCount = 0;
        $foundCount = 0;

        $this->output->progressStart($totalFiles);

        foreach ($files as $file) {
            $path = $file->path;

            // Check if the file exists
            $exists = false;

            if ($path) {
                // Check if the path is a URL
                if (filter_var($path, FILTER_VALIDATE_URL)) {
                    // For URLs, we assume they exist (we could add a HTTP check here if needed)
                    $exists = true;
                } else {
                    // For local files, check if they exist in the filesystem
                    $exists = file_exists($path);
                }
            }

            // Update the not_found flag
            $file->not_found = !$exists;
            $file->save();

            if (!$exists) {
                $notFoundCount++;
            } else {
                $foundCount++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->info("File existence check completed:");
        $this->info("- Total files: $totalFiles");
        $this->info("- Files found: $foundCount");
        $this->info("- Files not found: $notFoundCount");

        return Command::SUCCESS;
    }
}
