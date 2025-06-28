<?php

namespace App\Console\Commands;

use App\Models\File;
use FFMpeg\FFMpeg;
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
        File::where('mime_type', 'audio/mpeg')->chunk(100, function ($files) {
            foreach ($files as $file) {
                // execute node script /scripts/extract-metadata.js with file path as argument
                $this->info("Extracting metadata for file: {$file->path}");

                $output = shell_exec("node scripts/extract-metadata.js \"{$file->path}\"");

                dd(json_decode($output, true));

                Storage::put("metadata/{$file->id}.json", $output);

                if ($output) {
                    $this->info("Metadata extracted successfully: {$output}");
                } else {
                    $this->error("Failed to extract metadata for file: {$file->path}");
                }
            }
        });


        // file where audio
        $file = File::where('mime_type', 'audio/mpeg')
            ->first();

        if ($file) {
            // execute node script /scripts/extract-metadata.js with file path as argument
            $this->info("Extracting metadata for file: {$file->path}");

            $output = shell_exec("node scripts/extract-metadata.js \"{$file->path}\"");

            dd(json_decode($output, true));

            if ($output) {
                $this->info("Metadata extracted successfully: {$output}");
            } else {
                $this->error("Failed to extract metadata for file: {$file->path}");
            }
        }
    }
}
