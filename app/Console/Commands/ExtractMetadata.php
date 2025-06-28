<?php

namespace App\Console\Commands;

use App\Models\File;
use FFMpeg\FFMpeg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
        File::where('mime_type', 'like', 'audio/%')
            ->chunk(100, function ($files) {
            foreach ($files as $file) {
                // execute node script /scripts/extract-metadata.js with file path as argument
                $this->info("Extracting metadata for file: {$file->path}");

                $output = shell_exec("node scripts/extract-metadata.js \"{$file->path}\"");

                if ($output) {
                    $this->info("Metadata extracted successfully: \"{$file->path}\"");

                    Storage::put("metadata/{$file->id}.json", $output);

                    $file->metadata()->updateOrCreate(
                        ['file_id' => $file->id],
                        ['is_extracted' => true]
                    );
                } else {
                    $this->error("Failed to extract metadata for file: \"{$file->path}\"");
                }
            }
        });
    }
}
