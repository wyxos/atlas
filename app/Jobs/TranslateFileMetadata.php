<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Artist;
use App\Models\Cover;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TranslateFileMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected File $file,
        protected bool $force = false
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
        try {
            // Get the metadata JSON file
            $metadataPath = "metadata/{$this->file->id}.json";

            if (! Storage::disk('atlas')->exists($metadataPath)) {
                return;
            }

            $metadataJson = Storage::disk('atlas')->get($metadataPath);
            $metadata = json_decode($metadataJson, true);

            if (! $metadata) {
                // Mark as review required
                if ($this->file->metadata()->exists()) {
                    $this->file->metadata()->update([
                        'is_review_required' => true,
                    ]);
                } else {
                    $this->file->metadata()->create([
                        'is_review_required' => true,
                    ]);
                }

                return;
            }

            // Extract relevant fields from metadata
            $translatedData = $this->translateMetadata($metadata, $this->file);

            if ($this->file->metadata()->exists()) {
                // If metadata already exists, update it
                $this->file->metadata()->update($translatedData);
            } else {
                // Create new metadata record
                $this->file->metadata()->create($translatedData);
            }
        } catch (\Exception $e) {
            // Mark as review required
            $this->file->metadata()->update([
                'is_review_required' => true,
            ]);
        }
    }

    /**
     * Translate metadata into structured entities
     *
     * @param  array  $metadata  The raw metadata
     * @param  File  $file  The file being processed
     * @return array Translated data and review status
     */
    protected function translateMetadata(array $metadata, File $file): array
    {
        $payload = [];
        $isReviewRequired = false;

        // Default empty values
        $title = null;
        $artist = null;
        $album = null;
        $year = null;
        $track = null;
        $coverArtPath = null; // default
        $coverArtData = null; // Store cover data for later processing

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
                    case 'PIC':
                        // Store cover data for later processing after artist/album creation
                        if (isset($tag['value']['data'])) {
                            $coverArtData = [
                                'binary' => $this->decodeCoverArt($tag['value']['data']),
                                'mime' => $tag['value']['format'] ?? 'image/jpeg',
                            ];
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
            $payload['codec'] = $format['codec'] ?? null;
            $payload['container'] = $format['container'] ?? null;
            $payload['sample_rate'] = $format['sampleRate'] ?? null;
            $payload['channels'] = $format['numberOfChannels'] ?? null;
            $payload['bitrate'] = $format['bitrate'] ?? null;
            $payload['encoder'] = $format['tool'] ?? null;
            $payload['duration'] = $format['duration'] ?? null;
        }

        // We no longer store cover_art_path in the metadata payload
        // since we're already creating a cover entry

        // If we couldn't extract basic metadata, mark for review
        if (empty($title) && empty($artist) && empty($album)) {
            $isReviewRequired = true;
        }

        // Use filename as title if no title found
        if (empty($title)) {
            $title = $file->filename;
        }

        // Update payload data
        $payload['title'] = $title;

        // Create or find artist(s) and associate with file
        if (! empty($artist)) {
            // Check if there are multiple artists (separated by commas, semicolons, or '&')
            $artistNames = preg_split('/[,;&]+/', $artist);
            $artistIds = [];

            foreach ($artistNames as $artistName) {
                $artistName = trim($artistName);
                if (! empty($artistName)) {
                    $artistModel = Artist::firstOrCreate(['name' => $artistName]);
                    $artistIds[] = $artistModel->id;
                }
            }

            if (! empty($artistIds)) {
                $file->artists()->syncWithoutDetaching($artistIds);
            }
        }

        // Create or find album(s) and associate with file
        if (! empty($album)) {
            // Check if there are multiple albums (separated by commas, semicolons, or '&')
            $albumNames = preg_split('/[,;&]+/', $album);
            $albumIds = [];

            foreach ($albumNames as $albumName) {
                $albumName = trim($albumName);
                if (! empty($albumName)) {
                    $albumModel = Album::firstOrCreate(['name' => $albumName]);
                    $albumIds[] = $albumModel->id;
                }
            }

            if (! empty($albumIds)) {
                $file->albums()->syncWithoutDetaching($albumIds);
            }
        }

        // Process cover art after artist/album creation
        if ($coverArtData) {
            $extension = explode('/', $coverArtData['mime'])[1];
            $hash = md5($coverArtData['binary']);

            // Check if a cover with this hash already exists
            $existingCover = Cover::where('hash', $hash)->first();

            if ($existingCover) {
                // Update the cover path to point to the existing cover
                $coverArtPath = $existingCover->path;
            } else {
                // Create a new cover record and associate with artist or album
                DB::beginTransaction();

                try {
                    // Determine what to associate the cover with (prefer album, then artist)
                    $coverableId = null;
                    $coverableType = null;

                    if (!empty($albumIds)) {
                        $coverableId = $albumIds[0]; // Use first album
                        $coverableType = Album::class;
                    } elseif (!empty($artistIds)) {
                        $coverableId = $artistIds[0]; // Use first artist
                        $coverableType = Artist::class;
                    }

                    if ($coverableId && $coverableType) {
                        // Generate the path using a temporary ID
                        $tempId = Str::random(40);
                        $coverArtPath = "covers/{$tempId}.{$extension}";

                        // Save to storage
                        Storage::disk('atlas')->put($coverArtPath, $coverArtData['binary']);

                        // Create a new cover record with polymorphic association
                        $cover = Cover::create([
                            'hash' => $hash,
                            'path' => $coverArtPath,
                            'coverable_id' => $coverableId,
                            'coverable_type' => $coverableType,
                        ]);
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                }
            }
        }

        if (! empty($year)) {
            $payload['year'] = $year;
        }

        if (! empty($track)) {
            $payload['track'] = $track;
        }

        // Add format information if available
        if (isset($metadata['format'])) {
            if (isset($metadata['format']['duration']) && ! isset($payload['duration'])) {
                $payload['duration'] = $metadata['format']['duration'];
            }

            if (isset($metadata['format']['bitrate']) && ! isset($payload['bitrate'])) {
                $payload['bitrate'] = $metadata['format']['bitrate'];
            }

            if (isset($metadata['format']['sampleRate']) && ! isset($payload['sample_rate'])) {
                $payload['sampleRate'] = $metadata['format']['sampleRate'];
            }
        }

        // Merge with existing file tags
        $existingTags = $file->tags ?? [];
        foreach ($existingTags as $key => $value) {
            if (! isset($payload[$key])) {
                $payload[$key] = $value;
            }
        }

        // Explicitly remove artist and album from the payload as per requirements
        unset($payload['artist']);
        unset($payload['artists']);
        unset($payload['album']);
        unset($payload['albums']);

        return [
            'payload' => $payload,
            'is_review_required' => $isReviewRequired,
        ];
    }

    /**
     * Decode cover art data
     */
    private function decodeCoverArt(array $data): string
    {
        return pack('C*', ...array_values($data));
    }
}
