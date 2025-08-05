<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchPostImages implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        // receive a file instance
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // check for container of type post associated to the file
            $postContainer = $this->file->containers()->where('type', 'post')->first();
            
            if (!$postContainer) {
                return;
            }

            // retrieve the source_id of the container
            $postId = $postContainer->source_id;

            // use the fetchItems logic from CivitAIService as reference, and perform a get request to retrieve items, but pass the post_id
            $images = $this->fetchPostImages($postId);

            // for each images retrieved, create an entry in the database and associate it to the container
            foreach ($images as $image) {
                $this->processImage($image, $postContainer);
            }

            // for the original file $this->file, update it's metadata->payload->data to be now the data received in the above fetch
            // (This will be handled when the original file is processed in the loop above)
            
        } catch (\Exception $e) {
            $this->fail($e);
        }
    }
    
    private function fetchPostImages(string $postId): array
    {
        // Use the same endpoint as CivitAIService fetchFileData method
        $response = Http::get('https://civitai.com/api/v1/images', [
            'postId' => $postId,
            'limit' => 200, // Get all images for this post
        ]);
        
        if (!$response->successful()) {
            throw new \Exception('Failed to fetch images from CivitAI API');
        }
        
        $data = $response->json();
        return $data['items'] ?? [];
    }
    
    private function processImage(array $image, Container $postContainer): void
    {
        // Build URLs using the same pattern as CivitAIService
        $thumbnail = $image['url'];
        $thumbnail = preg_replace('/width=\d+/', 'width=450', $thumbnail);
        
        $referrerUrl = "https://civitai.com/images/{$image['id']}";
        
        // Create/update file entry
        $file = File::updateOrCreate(
            ['referrer_url' => $referrerUrl],
            [
                'source' => 'CivitAI',
                'source_id' => (string)$image['id'],
                'url' => $image['url'],
                'filename' => basename(parse_url($image['url'], PHP_URL_PATH)) ?: 'civitai_' . $image['id'],
                'ext' => $this->getFileExtension($image),
                'mime_type' => $this->getMimeType($image),
                'hash' => $image['hash'] ?? null,
                'thumbnail_url' => $thumbnail,
            ]
        );
        
        // Associate with container
        $postContainer->files()->syncWithoutDetaching([$file->id]);
        
        // update their metadata
        $metadata = array_merge($image['meta'] ?? [], [
            'width' => $image['width'] ?? null,
            'height' => $image['height'] ?? null,
            'civitai_id' => $image['id'],
            'civitai_stats' => $image['stats'] ?? null,
            'data' => $image,
        ]);
        
        FileMetadata::updateOrCreate(
            ['file_id' => $file->id],
            ['payload' => json_encode($metadata)]
        );
    }
    
    private function getFileExtension(array $itemData): string
    {
        return pathinfo(parse_url($itemData['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
    }
    
    private function getMimeType(array $itemData): string
    {
        $extension = strtolower($this->getFileExtension($itemData));
        
        return match ($extension) {
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }
}
