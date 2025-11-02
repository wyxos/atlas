<?php

namespace App\Jobs;

use App\Models\Container;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessFileListingMetadataJob implements ShouldQueue
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
            Log::warning("ProcessFileListingMetadataJob: File {$this->fileId} not found");

            return;
        }

        $listingMetadata = $file->listing_metadata;

        if (! is_array($listingMetadata)) {
            Log::debug("ProcessFileListingMetadataJob: File {$file->id} has no valid listing_metadata");

            return;
        }

        $attachments = [];

        // Process postId
        if (isset($listingMetadata['postId'])) {
            $postId = $listingMetadata['postId'];
            $postReferrer = "https://civitai.com/posts/{$postId}";

            $postContainer = Container::firstOrCreate(
                [
                    'type' => 'Post',
                    'source' => $file->source,
                    'source_id' => (string) $postId,
                ],
                [
                    'referrer' => $postReferrer,
                ]
            );

            if ($postContainer->wasRecentlyCreated) {
                Log::info("ProcessFileListingMetadataJob: Created Post container {$postContainer->id} for file {$file->id}");
            }

            $attachments[] = $postContainer->id;
        }

        // Process username
        if (isset($listingMetadata['username'])) {
            $username = $listingMetadata['username'];
            $userReferrer = "https://civitai.com/user/{$username}";

            $userContainer = Container::firstOrCreate(
                [
                    'type' => 'User',
                    'source' => $file->source,
                    'source_id' => (string) $username,
                ],
                [
                    'referrer' => $userReferrer,
                ]
            );

            if ($userContainer->wasRecentlyCreated) {
                Log::info("ProcessFileListingMetadataJob: Created User container {$userContainer->id} for file {$file->id}");
            }

            $attachments[] = $userContainer->id;
        }

        // Attach file to containers
        if (! empty($attachments)) {
            $existingIds = $file->containers()->pluck('containers.id')->toArray();
            $newAttachments = array_diff($attachments, $existingIds);

            if (! empty($newAttachments)) {
                $file->containers()->attach($newAttachments);
                Log::info("ProcessFileListingMetadataJob: Attached file {$file->id} to ".count($newAttachments).' container(s)');
            }
        }
    }
}
