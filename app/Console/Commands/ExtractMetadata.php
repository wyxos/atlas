<?php

namespace App\Console\Commands;

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
        // file where audio
        $file = File::where('mime_type', 'audio/mpeg')
            ->first();

        if (!$file) {

        }

        dd($file->path);
    }
}
