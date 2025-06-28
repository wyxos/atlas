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

class TranslateFileMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected File $file,
        protected bool $force = false
    ) {
    }

    /**
     * Get the file associated with this job.
     *
     * @return File
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
        try {
            // Get the metadata JSON file
            $metadataPath = "metadata/{$this->file->id}.json";

            if (!Storage::exists($metadataPath)) {
                Log::warning("Metadata file not found for file ID: {$this->file->id}");
                return;
            }

            $metadataJson = Storage::get($metadataPath);
            $metadata = json_decode($metadataJson, true);

            if (!$metadata) {
                Log::error("Failed to parse metadata JSON for file ID: {$this->file->id}");

                // Mark as review required
                $this->file->metadata()->update([
                    'is_review_required' => true
                ]);

                return;
            }

            // Extract relevant fields from metadata
            $translatedData = $this->translateMetadata($metadata, $this->file);

            // Update metadata record
            $this->file->metadata()->update([
                'payload' => $translatedData['file'] ?? [],
                'is_review_required' => $translatedData['is_review_required']
            ]);

            Log::info("Metadata translated successfully for file: {$this->file->path}");

        } catch (\Exception $e) {
            Log::error("Error processing file {$this->file->id}: " . $e->getMessage());

            // Mark as review required
            $this->file->metadata()->update([
                'is_review_required' => true
            ]);
        }
    }

    /**
     * Translate metadata into structured entities
     *
     * @param array $metadata The raw metadata
     * @param File $file The file being processed
     * @return array Translated data and review status
     */
    protected function translateMetadata(array $metadata, File $file): array
    {
        $fileData = [];
        $isReviewRequired = false;

        // Default empty values
        $title = null;
        $artist = null;
        $album = null;
        $year = null;
        $track = null;
        $coverArtPath = null; // default

        // Extract ID3 tags if available
        if (isset($metadata['native']['ID3v2.3'])) {
            foreach ($metadata['native']['ID3v2.3'] as $tag) {
                switch ($tag['id']) {
                    case 'TIT2': // Title
                        $title = $tag['value'] ?? null;
                        break;
                    case 'TPE1': // Artist
                        $artist = $tag['value'] ?? null;
                        break;
                    case 'TPE2': // Album Artist
                        if (empty($artist)) {
                            $artist = $tag['value'] ?? null;
                        }
                        break;
                    case 'TPOS': // Disc number
                        $disc = $tag['value'] ?? null;
                        break;
                    case 'TCON': // Genre
                        $genre = $tag['value'] ?? null;
                        break;
                    case 'TPUB': // Publisher
                        $publisher = $tag['value'] ?? null;
                        break;
                    case 'APIC':
                        if (isset($tag['value']['data'])) {
                            $coverArtBinary = $this->decodeCoverArt($tag['value']['data']);
                            $coverArtMime = $tag['value']['format'] ?? 'image/jpeg';

                            // Generate storage path (e.g. cover_art/{file_id}.jpg)
                            $coverArtPath = "cover-art/{$file->id}." . explode('/', $coverArtMime)[1];

                            // Save to storage
                            Storage::disk('public')->put($coverArtPath, $coverArtBinary);
                        }
                        break;
                    case 'TALB': // Album
                        $album = $tag['value'] ?? null;
                        break;
                    case 'TYER': // Year
                        $year = $tag['value'] ?? null;
                        break;
                    case 'TRCK': // Track number
                        $track = $tag['value'] ?? null;
                        break;
                }
            }
        } elseif (isset($metadata['native']['ID3v1'])) {
            // Fallback to ID3v1 if ID3v2 is not available
            foreach ($metadata['native']['ID3v1'] as $tag) {
                switch ($tag['id']) {
                    case 'title':
                        $title = $tag['value'] ?? null;
                        break;
                    case 'artist':
                        $artist = $tag['value'] ?? null;
                        break;
                    case 'album':
                        $album = $tag['value'] ?? null;
                        break;
                    case 'year':
                        $year = $tag['value'] ?? null;
                        break;
                    case 'track':
                        $track = $tag['value'] ?? null;
                        break;
                }
            }
        }

        if (isset($metadata['format'])) {
            $format = $metadata['format'];
            $fileData['codec'] = $format['codec'] ?? null;
            $fileData['container'] = $format['container'] ?? null;
            $fileData['sample_rate'] = $format['sampleRate'] ?? null;
            $fileData['channels'] = $format['numberOfChannels'] ?? null;
            $fileData['bitrate'] = $format['bitrate'] ?? null;
            $fileData['encoder'] = $format['tool'] ?? null;
            $fileData['duration'] = $format['duration'] ?? null;
        }

        if ($coverArtPath) {
            $fileData['cover_art_path'] = $coverArtPath;
        }

        // If we couldn't extract basic metadata, mark for review
        if (empty($title) && empty($artist) && empty($album)) {
            $isReviewRequired = true;
        }

        // Use filename as title if no title found
        if (empty($title)) {
            $title = $file->filename;
        }

        // Update file data
        $fileData['title'] = $title;

        // Add other metadata to tags if available
        $tags = $file->tags ?? [];

        if (!empty($artist)) {
            $tags['artist'] = $artist;
        }

        if (!empty($album)) {
            $tags['album'] = $album;
        }

        if (!empty($year)) {
            $tags['year'] = $year;
        }

        if (!empty($track)) {
            $tags['track'] = $track;
        }

        // Add format information if available
        if (isset($metadata['format'])) {
            if (isset($metadata['format']['duration'])) {
                $tags['duration'] = $metadata['format']['duration'];
            }

            if (isset($metadata['format']['bitrate'])) {
                $tags['bitrate'] = $metadata['format']['bitrate'];
            }

            if (isset($metadata['format']['sampleRate'])) {
                $tags['sampleRate'] = $metadata['format']['sampleRate'];
            }
        }

        $fileData['tags'] = $tags;

        return [
            'file' => $fileData,
            'is_review_required' => $isReviewRequired
        ];
    }

    /**
     * Decode cover art data
     *
     * @param array $data
     * @return string
     */
    private function decodeCoverArt(array $data): string
    {
        return pack('C*', ...array_values($data));
    }
}
