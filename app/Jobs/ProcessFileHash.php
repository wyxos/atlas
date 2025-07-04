<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use SplFileInfo;

class ProcessFileHash implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $filePath,
        public string $relativePath,
        public string $filename,
        public string $extension,
        public int $size,
        public ?string $mimeType,
        public string $title
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Check if file already exists in database (double-check in case of race conditions)
        $existingFile = File::where('path', $this->relativePath)->first();

        if ($existingFile) {
            return; // File already exists, skip processing
        }

        // Generate file hash - this is the intensive operation we're moving to a job
        $hash = hash_file('sha256', $this->filePath);

        // Create file record
        File::create([
            'source' => 'local',
            'path' => $this->relativePath,
            'filename' => $this->filename,
            'ext' => $this->extension ?: null,
            'size' => $this->size,
            'mime_type' => $this->mimeType,
            'hash' => $hash,
            'title' => $this->title,
            'downloaded' => true,
            'download_progress' => 100,
            'downloaded_at' => now(),
            'not_found' => false,
        ]);
    }
}
