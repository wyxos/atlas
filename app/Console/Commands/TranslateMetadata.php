<?php

namespace App\Console\Commands;

use App\Models\File;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TranslateMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:translate-metadata {--limit=100 : Number of files to process} {--force : Force reprocessing of already translated metadata}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Translate extracted audio file metadata into structured entities';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $force = $this->option('force');

        $this->info("Starting metadata translation process...");

        // Get files with extracted metadata that haven't been processed yet
        $query = File::audio()
            ->whereHas('metadata', function ($query) {
                $query->where('is_extracted', true);
            });

        // If not forcing reprocessing, exclude already processed files
        if (!$force) {
            $query->whereDoesntHave('metadata', function ($query) {
                $query->whereNotNull('payload');
            });
        }

        $count = 0;
        $successCount = 0;
        $errorCount = 0;

        $query->limit($limit)->chunk(20, function ($files) use (&$count, &$successCount, &$errorCount) {
            foreach ($files as $file) {
                $count++;
                $this->info("Processing file {$count}: {$file->path}");

                try {
                    // Get the metadata JSON file
                    $metadataPath = "metadata/{$file->id}.json";

                    if (!Storage::exists($metadataPath)) {
                        $this->warn("Metadata file not found for file ID: {$file->id}");
                        continue;
                    }

                    $metadataJson = Storage::get($metadataPath);
                    $metadata = json_decode($metadataJson, true);

                    if (!$metadata) {
                        $this->error("Failed to parse metadata JSON for file ID: {$file->id}");
                        $errorCount++;

                        // Mark as review required
                        $file->metadata()->update([
                            'is_review_required' => true
                        ]);

                        continue;
                    }

                    // Extract relevant fields from metadata
                    $translatedData = $this->translateMetadata($metadata, $file);

//                    dd($translatedData);

                    // Update file with translated metadata
//                    $file->update($translatedData['file']);

                    // Update metadata record
                    $file->metadata()->update([
                        'payload' => $metadata['file'] ?? [],
                        'is_review_required' => $translatedData['is_review_required']
                    ]);

                    $successCount++;

                } catch (\Exception $e) {
                    $this->error("Error processing file {$file->id}: " . $e->getMessage());
                    $errorCount++;

                    // Mark as review required
                    $file->metadata()->update([
                        'is_review_required' => true
                    ]);
                }
            }
        });

        $this->info("Metadata translation completed.");
        $this->info("Processed: {$count} files");
        $this->info("Success: {$successCount} files");
        $this->info("Errors: {$errorCount} files");
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

    private function decodeCoverArt(array $data): string
    {
        return pack('C*', ...array_values($data));
    }
}
