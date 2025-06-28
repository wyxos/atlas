<?php

namespace App\Console\Commands;

use App\Jobs\TranslateFileMetadata;
use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TranslateMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:translate-metadata {--limit=100 : Number of files to process} {--force : Force reprocessing of already translated metadata}';

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
        $limit = $this->option('limit');
        $force = $this->option('force');

        $this->info("Starting metadata translation process...");

        // Get files with extracted metadata that haven't been processed yet
        $query = File::audio()
            ->whereDoesntHave('metadata');

        // If not forcing reprocessing, exclude already processed files
        if (!$force) {
            $query->whereDoesntHave('metadata', function ($query) {
                $query->whereNotNull('payload');
            });
        }

        $count = 0;

        $query->chunk(20, function ($files) use (&$count, $force) {
            foreach ($files as $file) {
                $count++;
                $this->info("Dispatching job for file {$count}: {$file->path}");

                // Dispatch the job to translate metadata
                TranslateFileMetadata::dispatch($file, $force);
            }
        });

        $this->info("Metadata translation jobs dispatched successfully.");
        $this->info("Total files queued for processing: {$count}");
        $this->info("Check the logs for processing results.");
    }

}
