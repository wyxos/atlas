<?php

namespace App\Console\Commands;

use App\Jobs\TranslateFileMetadata;
use App\Models\File;
use Illuminate\Console\Command;

class TranslateMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:translate-metadata {--force : Force reprocessing of already translated metadata} {--file= : Process only the specified file ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate extracted audio file metadata into structured entities';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $force = $this->option('force');
        $fileId = $this->option('file');

        $this->info('Starting metadata translation process...');

        // Get files with extracted metadata that haven't been processed yet
        $query = File::audio();

        // If a specific file ID is provided, only process that file
        if ($fileId) {
            $query->where('id', $fileId);
            $this->info("Processing only file with ID: {$fileId}");
        }

        // If not forcing reprocessing, exclude already processed files
        if (! $force) {
            $query->whereDoesntHave('metadata');
        }

        $count = 0;

        // If a specific file ID is provided, get it directly instead of chunking
        if ($fileId) {
            $file = $query->first();

            if ($file) {
                $count++;
                $this->info("Dispatching job for file: {$file->path}");
                TranslateFileMetadata::dispatch($file, $force);
            } else {
                $this->error("File with ID {$fileId} not found or is not an audio file.");
            }
        } else {
            // Process files in chunks if no specific file ID is provided
            $query->chunk(20, function ($files) use (&$count, $force) {
                foreach ($files as $file) {
                    $count++;
                    $this->info("Dispatching job for file {$count}: {$file->path}");

                    // Dispatch the job to translate metadata
                    TranslateFileMetadata::dispatch($file, $force);
                }
            });
        }

        $this->info('Metadata translation jobs dispatched successfully.');
        $this->info("Total files queued for processing: {$count}");
        $this->info('Check the logs for processing results.');
    }
}
