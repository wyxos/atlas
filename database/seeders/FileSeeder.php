<?php

namespace Database\Seeders;

use App\Models\File;
use Illuminate\Database\Seeder;

class FileSeeder extends Seeder
{
    /**
     * Determine file type directory from mime type.
     */
    private function getFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'images';
        }
        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mimeType, 'video/')) {
            return 'videos';
        }
        
        // Default fallback
        return 'images';
    }

    /**
     * Copy a fixture file to storage with the given filename.
     * 
     * @param string $fixturePath Path relative to tests/fixtures/
     * @param string $targetFilename The filename to use in storage
     * @param string $mimeType The MIME type of the file
     * @return string|null The full path where the file was copied, or null if fixture doesn't exist
     */
    private function copyFixtureToStorage(string $fixturePath, string $targetFilename, string $mimeType): ?string
    {
        $source = base_path("tests/fixtures/{$fixturePath}");
        
        if (! file_exists($source)) {
            $this->command?->warn("Fixture file not found: {$fixturePath}");
            return null;
        }
        
        // Generate hash from file content for consistent subfolder placement
        $hash = hash_file('sha256', $source);
        $type = $this->getFileType($mimeType);
        
        // Use getStoragePath to ensure directories exist and get the full path
        $destination = File::getStoragePath($type, $targetFilename, $hash);
        
        // Copy file from fixtures to storage
        copy($source, $destination);
        
        return $destination;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Copy all fixture files to storage
        // These will be used as the base files that can be referenced by different file entries
        $this->copyFixtureToStorage('images/sample-image-1.jpg', 'sample-image-1.jpg', 'image/jpeg');
        $this->copyFixtureToStorage('images/sample-image-2.jpg', 'sample-image-2.jpg', 'image/jpeg');
        $this->copyFixtureToStorage('audio/sample-audio-1.mp3', 'sample-audio-1.mp3', 'audio/mpeg');
        $this->copyFixtureToStorage('audio/sample-audio-2.mp3', 'sample-audio-2.mp3', 'audio/mpeg');
        $this->copyFixtureToStorage('videos/sample-video-1.mp4', 'sample-video-1.mp4', 'video/mp4');
        $this->copyFixtureToStorage('videos/sample-video-2.mp4', 'sample-video-2.mp4', 'video/mp4');

        // Local files (with path, no URL)
        // Paths will be generated dynamically from fixture files
        $localFiles = [
            [
                'source' => 'local',
                'filename' => 'sample-image-1.jpg',
                'ext' => 'jpg',
                'url' => null,
                'mime_type' => 'image/jpeg',
                'title' => 'Local Sample Image 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-image-2.jpg',
                'ext' => 'jpg',
                'url' => null,
                'mime_type' => 'image/jpeg',
                'title' => 'Local Sample Image 2',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-audio-1.mp3',
                'ext' => 'mp3',
                'url' => null,
                'mime_type' => 'audio/mpeg',
                'title' => 'Local Sample Audio 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-audio-2.mp3',
                'ext' => 'mp3',
                'url' => null,
                'mime_type' => 'audio/mpeg',
                'title' => 'Local Sample Audio 2',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-video-1.mp4',
                'ext' => 'mp4',
                'url' => null,
                'mime_type' => 'video/mp4',
                'title' => 'Local Sample Video 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-video-2.mp4',
                'ext' => 'mp4',
                'url' => null,
                'mime_type' => 'video/mp4',
                'title' => 'Local Sample Video 2',
                'downloaded' => false,
            ],
        ];

        // Online files (with URL, no path)
        $onlineFiles = [
            [
                'source' => 'YouTube',
                'filename' => 'big-buck-bunny.mp4',
                'ext' => 'mp4',
                'path' => null,
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Big Buck Bunny - Online Video',
                'downloaded' => false,
            ],
            [
                'source' => 'YouTube',
                'filename' => 'elephants-dream.mp4',
                'ext' => 'mp4',
                'path' => null,
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Elephants Dream - Online Video',
                'downloaded' => false,
            ],
            [
                'source' => 'Booru',
                'filename' => 'random-image-1.jpg',
                'ext' => 'jpg',
                'path' => null,
                'url' => 'https://picsum.photos/1920/1080',
                'mime_type' => 'image/jpeg',
                'title' => 'Random Image 1 - Online',
                'downloaded' => false,
            ],
            [
                'source' => 'Booru',
                'filename' => 'random-image-2.jpg',
                'ext' => 'jpg',
                'path' => null,
                'url' => 'https://picsum.photos/1600/900',
                'mime_type' => 'image/jpeg',
                'title' => 'Random Image 2 - Online',
                'downloaded' => false,
            ],
            [
                'source' => 'NAS',
                'filename' => 'sample-audio-online.mp3',
                'ext' => 'mp3',
                'path' => null,
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3',
                'mime_type' => 'audio/mpeg',
                'title' => 'SoundHelix Song 1 - Online',
                'downloaded' => false,
            ],
            [
                'source' => 'NAS',
                'filename' => 'sample-audio-online-2.mp3',
                'ext' => 'mp3',
                'path' => null,
                'url' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3',
                'mime_type' => 'audio/mpeg',
                'title' => 'SoundHelix Song 2 - Online',
                'downloaded' => false,
            ],
        ];

        // Downloaded files (with path and URL - simulating files downloaded from online sources)
        // These will reuse existing files by finding them in storage
        $downloadedFiles = [
            [
                'source' => 'YouTube',
                'filename' => 'big-buck-bunny-downloaded.mp4',
                'ext' => 'mp4',
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Big Buck Bunny - Downloaded',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(5),
                'reuse_file' => 'sample-video-1.mp4', // Reuse this existing file
            ],
            [
                'source' => 'YouTube',
                'filename' => 'elephants-dream-downloaded.mp4',
                'ext' => 'mp4',
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Elephants Dream - Downloaded',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(3),
                'reuse_file' => 'sample-video-2.mp4', // Reuse this existing file
            ],
            [
                'source' => 'Booru',
                'filename' => 'downloaded-image-1.jpg',
                'ext' => 'jpg',
                'url' => 'https://picsum.photos/1920/1080',
                'mime_type' => 'image/jpeg',
                'title' => 'Downloaded Image 1',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(7),
                'reuse_file' => 'sample-image-1.jpg', // Reuse this existing file
            ],
            [
                'source' => 'Booru',
                'filename' => 'downloaded-image-2.jpg',
                'ext' => 'jpg',
                'url' => 'https://picsum.photos/1600/900',
                'mime_type' => 'image/jpeg',
                'title' => 'Downloaded Image 2',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(2),
                'reuse_file' => 'sample-image-2.jpg', // Reuse this existing file
            ],
        ];

        // Process local files
        foreach ($localFiles as $fileData) {
            $type = $this->getFileType($fileData['mime_type']);
            
            // Find the file in storage (should have been copied from fixtures)
            // Try new subfolder structure first, then old flat structure for backward compatibility
            $hash = hash('sha256', $fileData['filename']);
            $newPath = File::getStoragePath($type, $fileData['filename'], $hash);
            $oldPath = storage_path("app/private/{$type}/{$fileData['filename']}");
            
            $fullPath = null;
            if (file_exists($newPath)) {
                $fullPath = $newPath;
                // Recalculate hash from actual file content
                $hash = hash_file('sha256', $fullPath);
            } elseif (file_exists($oldPath)) {
                // File exists in old location, generate hash and move to new location
                $hash = hash_file('sha256', $oldPath);
                $newPath = File::getStoragePath($type, $fileData['filename'], $hash);
                
                // Move file to new subfolder structure
                if (! file_exists($newPath)) {
                    rename($oldPath, $newPath);
                }
                $fullPath = $newPath;
            } else {
                // File doesn't exist, this shouldn't happen if fixtures are set up correctly
                $this->command?->warn("File not found for local file: {$fileData['filename']}");
                $fullPath = $newPath;
            }
            
            // Generate relative path for database
            $relativePath = File::generateStoragePath($type, $fileData['filename'], $hash);
            $size = file_exists($fullPath) ? filesize($fullPath) : null;

            File::create(array_merge($fileData, [
                'path' => $relativePath,
                'hash' => $hash,
                'size' => $size,
                'created_at' => now()->subDays(rand(1, 30)),
            ]));
        }

        // Process online files
        foreach ($onlineFiles as $fileData) {
            File::create(array_merge($fileData, [
                'size' => null, // Unknown size for online files
                'created_at' => now()->subDays(rand(1, 30)),
            ]));
        }

        // Process downloaded files
        // These simulate files that were downloaded from online sources
        // They reuse the fixture files but with different metadata
        foreach ($downloadedFiles as $fileData) {
            $type = $this->getFileType($fileData['mime_type']);
            $reuseFile = $fileData['reuse_file'] ?? null;
            unset($fileData['reuse_file']); // Remove from data before creating
            
            // Find the file to reuse (should have been copied from fixtures)
            $fullPath = null;
            $hash = null;
            
            if ($reuseFile) {
                // Try to find the file to reuse in new structure first
                $reuseHash = hash('sha256', $reuseFile);
                $reusePath = File::getStoragePath($type, $reuseFile, $reuseHash);
                
                if (file_exists($reusePath)) {
                    // Found the file, use its hash
                    $hash = hash_file('sha256', $reusePath);
                    $fullPath = $reusePath;
                } else {
                    // Try old flat structure
                    $oldReusePath = storage_path("app/private/{$type}/{$reuseFile}");
                    if (file_exists($oldReusePath)) {
                        $hash = hash_file('sha256', $oldReusePath);
                        $fullPath = $oldReusePath;
                    }
                }
            }
            
            // If we couldn't find the file to reuse, generate a new path
            if (! $fullPath) {
                $hash = hash('sha256', $fileData['url']);
                $fullPath = File::getStoragePath($type, $fileData['filename'], $hash);
            }
            
            // Generate relative path for database (use the downloaded filename, not the reused one)
            $relativePath = File::generateStoragePath($type, $fileData['filename'], $hash);
            $size = file_exists($fullPath) ? filesize($fullPath) : null;

            File::create(array_merge($fileData, [
                'path' => $relativePath,
                'hash' => $hash,
                'size' => $size,
                'created_at' => now()->subDays(rand(1, 30)),
            ]));
        }
    }
}
