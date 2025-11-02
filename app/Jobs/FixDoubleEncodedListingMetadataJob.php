<?php

namespace App\Jobs;

use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FixDoubleEncodedListingMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $fileId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $file = File::find($this->fileId);

        if (! $file) {
            Log::warning("FixDoubleEncodedListingMetadataJob: File {$this->fileId} not found");

            return;
        }

        $listingMetadata = $file->listing_metadata;

        // If it's already a valid array, nothing to fix
        if (is_array($listingMetadata)) {
            Log::debug("FixDoubleEncodedListingMetadataJob: File {$file->id} already has valid array metadata");

            return;
        }

        // If it's a string, it might be double-encoded
        if (is_string($listingMetadata)) {
            try {
                // Try to decode the string
                $decoded = json_decode($listingMetadata, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    // Successfully decoded - save the fixed metadata
                    $file->listing_metadata = $decoded;
                    $file->save();

                    Log::info("FixDoubleEncodedListingMetadataJob: Fixed double-encoded metadata for file {$file->id}");
                } else {
                    Log::warning("FixDoubleEncodedListingMetadataJob: File {$file->id} has string metadata but could not decode it", [
                        'json_error' => json_last_error_msg(),
                        'metadata_preview' => substr($listingMetadata, 0, 100),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error("FixDoubleEncodedListingMetadataJob: Exception while fixing file {$file->id}", [
                    'error' => $e->getMessage(),
                    'metadata_preview' => substr($listingMetadata, 0, 100),
                ]);
            }
        } else {
            Log::debug("FixDoubleEncodedListingMetadataJob: File {$file->id} metadata is neither array nor string");
        }
    }
}
