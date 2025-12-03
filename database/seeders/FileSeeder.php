<?php

namespace Database\Seeders;

use App\Models\File;
use Illuminate\Database\Seeder;

class FileSeeder extends Seeder
{
    /**
     * Available fixture files that can be used for local and downloaded files.
     */
    private array $fixtures = [
        [
            'path' => 'images/sample-image-1.jpg',
            'filename' => 'sample-image-1.jpg',
            'ext' => 'jpg',
            'mime_type' => 'image/jpeg',
            'type' => 'images',
        ],
        [
            'path' => 'images/sample-image-2.jpg',
            'filename' => 'sample-image-2.jpg',
            'ext' => 'jpg',
            'mime_type' => 'image/jpeg',
            'type' => 'images',
        ],
        [
            'path' => 'audio/sample-audio-1.mp3',
            'filename' => 'sample-audio-1.mp3',
            'ext' => 'mp3',
            'mime_type' => 'audio/mpeg',
            'type' => 'audio',
        ],
        [
            'path' => 'audio/sample-audio-2.mp3',
            'filename' => 'sample-audio-2.mp3',
            'ext' => 'mp3',
            'mime_type' => 'audio/mpeg',
            'type' => 'audio',
        ],
        [
            'path' => 'videos/sample-video-1.mp4',
            'filename' => 'sample-video-1.mp4',
            'ext' => 'mp4',
            'mime_type' => 'video/mp4',
            'type' => 'videos',
        ],
        [
            'path' => 'videos/sample-video-2.mp4',
            'filename' => 'sample-video-2.mp4',
            'ext' => 'mp4',
            'mime_type' => 'video/mp4',
            'type' => 'videos',
        ],
    ];

    /**
     * Online URL templates for generating online files.
     */
    private array $onlineTemplates = [
        [
            'source' => 'YouTube',
            'url_template' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/{name}.mp4',
            'filename_template' => '{name}.mp4',
            'ext' => 'mp4',
            'mime_type' => 'video/mp4',
            'type' => 'videos',
            'names' => ['BigBuckBunny', 'ElephantsDream', 'ForBiggerBlazes', 'ForBiggerEscapes', 'ForBiggerFun', 'ForBiggerJoyrides', 'ForBiggerMeltdowns', 'Sintel', 'SubaruOutbackOnStreet', 'TearsOfSteel'],
        ],
        [
            'source' => 'Booru',
            'url_template' => 'https://picsum.photos/{width}/{height}?random={id}',
            'filename_template' => 'random-image-{id}.jpg',
            'ext' => 'jpg',
            'mime_type' => 'image/jpeg',
            'type' => 'images',
            'widths' => [1920, 1600, 1280, 1024, 800],
            'heights' => [1080, 900, 720, 768, 600],
        ],
        [
            'source' => 'NAS',
            'url_template' => 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-{id}.mp3',
            'filename_template' => 'sample-audio-{id}.mp3',
            'ext' => 'mp3',
            'mime_type' => 'audio/mpeg',
            'type' => 'audio',
            'ids' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10],
        ],
    ];

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
     * @param  string  $fixturePath  Path relative to tests/fixtures/
     * @param  string  $targetFilename  The filename to use in storage
     * @param  string  $mimeType  The MIME type of the file
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
     * Get a fixture by index, cycling through available fixtures.
     */
    private function getFixture(int $index): array
    {
        return $this->fixtures[$index % count($this->fixtures)];
    }

    /**
     * Generate online file data from templates.
     */
    private function generateOnlineFileData(int $index): array
    {
        $template = $this->onlineTemplates[$index % count($this->onlineTemplates)];

        if (isset($template['names'])) {
            $name = $template['names'][$index % count($template['names'])];
            $url = str_replace('{name}', $name, $template['url_template']);
            $filename = str_replace('{name}', strtolower($name), $template['filename_template']);
        } elseif (isset($template['ids'])) {
            $id = $template['ids'][$index % count($template['ids'])];
            $url = str_replace('{id}', (string) $id, $template['url_template']);
            $filename = str_replace('{id}', (string) $id, $template['filename_template']);
        } else {
            $width = $template['widths'][$index % count($template['widths'])];
            $height = $template['heights'][$index % count($template['heights'])];
            $id = $index + 1;
            $url = str_replace(['{width}', '{height}', '{id}'], [(string) $width, (string) $height, (string) $id], $template['url_template']);
            $filename = str_replace('{id}', (string) $id, $template['filename_template']);
        }

        return [
            'source' => $template['source'],
            'filename' => $filename,
            'ext' => $template['ext'],
            'path' => null,
            'url' => $url,
            'mime_type' => $template['mime_type'],
            'title' => ucfirst($template['source']).' File '.($index + 1),
            'downloaded' => false,
        ];
    }

    /**
     * Find a file in storage by filename and type.
     */
    private function findFileInStorage(string $filename, string $type): ?array
    {
        // Try new subfolder structure first
        $hash = hash('sha256', $filename);
        $newPath = File::getStoragePath($type, $filename, $hash);

        if (file_exists($newPath)) {
            return [
                'path' => $newPath,
                'hash' => hash_file('sha256', $newPath),
            ];
        }

        // Try old flat structure for backward compatibility
        $oldPath = storage_path("app/private/{$type}/{$filename}");
        if (file_exists($oldPath)) {
            $hash = hash_file('sha256', $oldPath);
            $newPath = File::getStoragePath($type, $filename, $hash);

            // Move file to new subfolder structure
            if (! file_exists($newPath)) {
                rename($oldPath, $newPath);
            }

            return [
                'path' => $newPath,
                'hash' => $hash,
            ];
        }

        return null;
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Copy all fixture files to storage
        // These will be used as the base files that can be referenced by different file entries
        foreach ($this->fixtures as $fixture) {
            $this->copyFixtureToStorage($fixture['path'], $fixture['filename'], $fixture['mime_type']);
        }

        // Generate 10 local files (with path, no URL, downloaded = false)
        for ($i = 0; $i < 10; $i++) {
            $fixture = $this->getFixture($i);
            $fileInfo = $this->findFileInStorage($fixture['filename'], $fixture['type']);

            if (! $fileInfo) {
                $this->command?->warn("File not found for local file: {$fixture['filename']}");
                $hash = hash('sha256', $fixture['filename']);
                $fileInfo = [
                    'path' => File::getStoragePath($fixture['type'], $fixture['filename'], $hash),
                    'hash' => $hash,
                ];
            }

            $relativePath = File::generateStoragePath($fixture['type'], $fixture['filename'], $fileInfo['hash']);
            $size = file_exists($fileInfo['path']) ? filesize($fileInfo['path']) : null;

            File::create([
                'source' => 'local',
                'filename' => "local-{$i}-{$fixture['filename']}",
                'ext' => $fixture['ext'],
                'url' => null,
                'path' => $relativePath,
                'mime_type' => $fixture['mime_type'],
                'title' => 'Local File '.($i + 1),
                'downloaded' => false,
                'hash' => $fileInfo['hash'],
                'size' => $size,
                'created_at' => now()->subDays(rand(1, 30)),
            ]);
        }

        // Generate 10 online files (with URL, no path, downloaded = false)
        for ($i = 0; $i < 10; $i++) {
            $fileData = $this->generateOnlineFileData($i);

            File::create(array_merge($fileData, [
                'size' => null, // Unknown size for online files
                'created_at' => now()->subDays(rand(1, 30)),
            ]));
        }

        // Generate 10 downloaded files (with path and URL, downloaded = true)
        // These reuse the fixture files but with different metadata
        for ($i = 0; $i < 10; $i++) {
            $fixture = $this->getFixture($i);
            $onlineData = $this->generateOnlineFileData($i);

            $fileInfo = $this->findFileInStorage($fixture['filename'], $fixture['type']);

            if (! $fileInfo) {
                $hash = hash('sha256', $onlineData['url']);
                $fileInfo = [
                    'path' => File::getStoragePath($fixture['type'], $onlineData['filename'], $hash),
                    'hash' => $hash,
                ];
            }

            $relativePath = File::generateStoragePath($fixture['type'], $onlineData['filename'], $fileInfo['hash']);
            $size = file_exists($fileInfo['path']) ? filesize($fileInfo['path']) : null;

            File::create([
                'source' => $onlineData['source'],
                'filename' => "downloaded-{$i}-{$onlineData['filename']}",
                'ext' => $onlineData['ext'],
                'url' => $onlineData['url'],
                'path' => $relativePath,
                'mime_type' => $onlineData['mime_type'],
                'title' => 'Downloaded File '.($i + 1),
                'downloaded' => true,
                'downloaded_at' => now()->subDays(rand(1, 30)),
                'hash' => $fileInfo['hash'],
                'size' => $size,
                'created_at' => now()->subDays(rand(1, 30)),
            ]);
        }
    }
}
