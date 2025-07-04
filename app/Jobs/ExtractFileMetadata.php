<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExtractFileMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected File $file
    ) {}

    /**
     * Get the file associated with this job.
     */
    public function getFile(): File
    {
        return $this->file;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Execute node script to extract metadata
        $output = $this->executeMetadataScript(Storage::disk('atlas')->path($this->file->path));

        if ($output) {
            // Store the metadata JSON in storage
            Storage::disk('atlas')->put("metadata/{$this->file->id}.json", $output);

            // Update or create the metadata record
            $this->file->metadata()->updateOrCreate(
                ['file_id' => $this->file->id],
                ['is_extracted' => true]
            );

            Log::info("Metadata extracted successfully for file: {$this->file->path}");
        } else {
            Log::error("Failed to extract metadata for file: {$this->file->path}");
        }
    }

    /**
     * Execute the metadata extraction script.
     */
    protected function executeMetadataScript(string $filePath): ?string
    {
        return shell_exec("node scripts/extract-metadata.js \"{$filePath}\"");
    }
}
