<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Artist;
use App\Models\FileMetadata;
use Illuminate\Support\Facades\Storage;

class ProcessAudioJob extends BaseProcessJob
{
    protected function process(): void
    {
        $file = $this->file->fresh(['metadata']) ?? $this->file;
        if (! $file || ! $file->exists) {
            return;
        }

        $path = $file->path;
        if (! $path) {
            return;
        }

        // Check if metadata already exists and is extracted
        $metadata = $file->metadata;
        if ($metadata && $metadata->is_extracted) {
            return;
        }

        try {
            $disk = $this->disk;
            $storage = Storage::disk($disk);

            // For local disks, use path directly
            // For remote disks (S3, etc.), we'd need to download temporarily
            $filePath = null;
            $tempPath = null;
            if (method_exists($storage, 'path')) {
                $filePath = $storage->path($path);
                if (! file_exists($filePath)) {
                    return;
                }
            } else {
                // Remote storage - download to temp file
                $tempPath = sys_get_temp_dir().'/'.basename($path).'_'.uniqid();
                $contents = $storage->get($path);
                if (! is_string($contents) || $contents === '') {
                    return;
                }
                file_put_contents($tempPath, $contents);
                $filePath = $tempPath;
            }

            // Extract metadata using getID3
            $audioMetadata = $this->extractAudioMetadata($filePath);

            // Clean up temp file if we created one
            if ($tempPath !== null && file_exists($tempPath)) {
                @unlink($tempPath);
            }
            if (! $audioMetadata || empty($audioMetadata)) {
                return;
            }

            // Extract and save artists
            $this->extractAndSaveArtists($file, $audioMetadata);

            // Extract and save albums
            $this->extractAndSaveAlbums($file, $audioMetadata);

            // Save full metadata to FileMetadata
            if (! $metadata) {
                $metadata = new FileMetadata([
                    'file_id' => $file->id,
                    'payload' => [],
                    'is_review_required' => false,
                    'is_extracted' => false,
                ]);
            }

            $metadata->payload = $audioMetadata;
            $metadata->is_extracted = true;
            $metadata->save();

            if (! $file->metadata) {
                $file->metadata()->save($metadata);
            }

            // Persist the file
            $file->save();
        } catch (\Throwable $e) {
            report($e);

            return;
        }
    }

    protected function extractAudioMetadata(string $filePath): ?array
    {
        // Check if getID3 is available
        if (! class_exists('getID3')) {
            // Try to use getID3 if available via composer
            // For now, return null if not available
            // TODO: Add getid3/getid3 to composer.json: composer require getid3/getid3
            return null;
        }

        try {
            // Use variable class name to avoid static analysis errors for optional dependency
            $getID3Class = 'getID3';
            if (! class_exists($getID3Class)) {
                return null;
            }

            /** @phpstan-ignore-next-line */
            $getID3 = new $getID3Class;
            /** @phpstan-ignore-next-line */
            $fileInfo = $getID3->analyze($filePath);

            // Clean up the metadata array (getID3 includes file paths which we don't need)
            $libClass = 'getID3_lib';
            if (class_exists($libClass)) {
                /** @phpstan-ignore-next-line */
                $libClass::CopyTagsToComments($fileInfo);
            }

            // Extract relevant metadata
            $metadata = [];

            // Basic audio info
            if (isset($fileInfo['audio'])) {
                $metadata['audio'] = $fileInfo['audio'];
            }

            // ID3v2 tags (most common)
            if (isset($fileInfo['tags']['id3v2'])) {
                $tags = $fileInfo['tags']['id3v2'];
                $metadata['title'] = $this->getTagValue($tags, 'title');
                $metadata['artist'] = $this->getTagValue($tags, 'artist');
                $metadata['album'] = $this->getTagValue($tags, 'album');
                $metadata['year'] = $this->getTagValue($tags, 'year');
                $metadata['genre'] = $this->getTagValue($tags, 'genre');
                $metadata['track'] = $this->getTagValue($tags, 'track_number');
                $metadata['comment'] = $this->getTagValue($tags, 'comment');
            }

            // ID3v1 tags (fallback)
            if (isset($fileInfo['tags']['id3v1'])) {
                $tags = $fileInfo['tags']['id3v1'];
                if (empty($metadata['title'])) {
                    $metadata['title'] = $this->getTagValue($tags, 'title');
                }
                if (empty($metadata['artist'])) {
                    $metadata['artist'] = $this->getTagValue($tags, 'artist');
                }
                if (empty($metadata['album'])) {
                    $metadata['album'] = $this->getTagValue($tags, 'album');
                }
                if (empty($metadata['year'])) {
                    $metadata['year'] = $this->getTagValue($tags, 'year');
                }
                if (empty($metadata['genre'])) {
                    $metadata['genre'] = $this->getTagValue($tags, 'genre');
                }
                if (empty($metadata['track'])) {
                    $metadata['track'] = $this->getTagValue($tags, 'track_number');
                }
                if (empty($metadata['comment'])) {
                    $metadata['comment'] = $this->getTagValue($tags, 'comment');
                }
            }

            // Vorbis comments (for OGG files)
            if (isset($fileInfo['tags']['vorbiscomment'])) {
                $tags = $fileInfo['tags']['vorbiscomment'];
                if (empty($metadata['title'])) {
                    $metadata['title'] = $this->getTagValue($tags, 'title');
                }
                if (empty($metadata['artist'])) {
                    $metadata['artist'] = $this->getTagValue($tags, 'artist');
                }
                if (empty($metadata['album'])) {
                    $metadata['album'] = $this->getTagValue($tags, 'album');
                }
                if (empty($metadata['year'])) {
                    $metadata['year'] = $this->getTagValue($tags, 'date');
                }
                if (empty($metadata['genre'])) {
                    $metadata['genre'] = $this->getTagValue($tags, 'genre');
                }
                if (empty($metadata['track'])) {
                    $metadata['track'] = $this->getTagValue($tags, 'tracknumber');
                }
            }

            // Store the full raw metadata for reference
            $metadata['raw'] = $fileInfo;

            return $metadata;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function getTagValue(array $tags, string $key): ?string
    {
        if (! isset($tags[$key])) {
            return null;
        }

        $value = $tags[$key];
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return $value ? trim((string) $value) : null;
    }

    protected function extractAndSaveArtists(\App\Models\File $file, array $metadata): void
    {
        $artistNames = [];

        // Get artist from metadata
        if (! empty($metadata['artist'])) {
            $artistStr = $metadata['artist'];
            // Handle multiple artists separated by common delimiters
            $artists = preg_split('/[;,|]/', $artistStr);
            foreach ($artists as $artist) {
                $artist = trim($artist);
                if ($artist !== '') {
                    $artistNames[] = $artist;
                }
            }
        }

        // Also check raw metadata for multiple artist fields
        if (isset($metadata['raw']['tags'])) {
            foreach ($metadata['raw']['tags'] as $tagType => $tags) {
                if (isset($tags['artist'])) {
                    $artists = is_array($tags['artist']) ? $tags['artist'] : [$tags['artist']];
                    foreach ($artists as $artist) {
                        $artist = trim((string) $artist);
                        if ($artist !== '' && ! in_array($artist, $artistNames, true)) {
                            $artistNames[] = $artist;
                        }
                    }
                }
            }
        }

        // Save artists
        foreach ($artistNames as $name) {
            if ($name === '') {
                continue;
            }
            $artist = Artist::firstOrCreate(['name' => $name]);
            $file->artists()->syncWithoutDetaching([$artist->id]);
        }
    }

    protected function extractAndSaveAlbums(\App\Models\File $file, array $metadata): void
    {
        $albumName = null;

        // Get album from metadata
        if (! empty($metadata['album'])) {
            $albumName = trim((string) $metadata['album']);
        }

        // Also check raw metadata
        if (empty($albumName) && isset($metadata['raw']['tags'])) {
            foreach ($metadata['raw']['tags'] as $tagType => $tags) {
                if (isset($tags['album'])) {
                    $album = is_array($tags['album']) ? ($tags['album'][0] ?? null) : $tags['album'];
                    if ($album) {
                        $albumName = trim((string) $album);
                        break;
                    }
                }
            }
        }

        // Save album
        if ($albumName && $albumName !== '') {
            $album = Album::firstOrCreate(['name' => $albumName]);
            $file->albums()->syncWithoutDetaching([$album->id]);
        }
    }
}
