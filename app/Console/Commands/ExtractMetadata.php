<?php

namespace App\Console\Commands;

use App\Jobs\ExtractFileMetadata;
use App\Models\File;
use Illuminate\Console\Command;

class ExtractMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:extract-metadata {--file= : Process only the specified file ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Extract metadata from files in the system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = 0;
        $fileId = $this->option('file');

        // If a specific file ID is provided, only process that file
        if ($fileId) {
            $this->info("Processing only file with ID: {$fileId}");
            $file = File::query()
                ->where(function ($query) {
                    $query->audio()
                        ->orWhere(fn ($q) => $q->video())
                        ->orWhere(fn ($q) => $q->image());
                })
                ->where('id', $fileId)->first();

            if ($file) {
                $this->info("Queuing metadata extraction for file: {$file->path}");
                ExtractFileMetadata::dispatch($file);
                $count = 1;
            } else {
                $this->error("File with ID {$fileId} not found or is not an audio file.");

                return Command::FAILURE;
            }
        } else {
            // Process all audio files in chunks if no specific file ID is provided
            File::query()
                ->where(function ($query) {
                    $query->audio()
                        ->orWhere(fn ($q) => $q->video())
                        ->orWhere(fn ($q) => $q->image());
                })
                ->chunk(100, function ($files) use (&$count) {
                    foreach ($files as $file) {
                        $this->info("Queuing metadata extraction for file: {$file->path}");

                        // Dispatch the job to the queue
                        ExtractFileMetadata::dispatch($file);

                        $count++;
                    }
                });
        }

        $this->info("Queued metadata extraction for {$count} files.");
    }
}
