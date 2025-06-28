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
    protected $signature = 'files:extract-metadata';

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

        File::where('mime_type', 'like', 'audio/%')
            ->chunk(100, function ($files) use (&$count) {
                foreach ($files as $file) {
                    $this->info("Queuing metadata extraction for file: {$file->path}");

                    // Dispatch the job to the queue
                    ExtractFileMetadata::dispatch($file);

                    $count++;
                }
            });

        $this->info("Queued metadata extraction for {$count} files.");
    }
}
