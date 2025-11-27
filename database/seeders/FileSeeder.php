<?php

namespace Database\Seeders;

use App\Models\File;
use Illuminate\Database\Seeder;

class FileSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Ensure storage directories exist
        $directories = [
            storage_path('app/private/images'),
            storage_path('app/private/audio'),
            storage_path('app/private/videos'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }

        // Copy files from fixtures to storage during seeding
        $fixtureFiles = [
            'images/sample-image-1.jpg' => 'private/images/sample-image-1.jpg',
            'images/sample-image-2.jpg' => 'private/images/sample-image-2.jpg',
            'audio/sample-audio-1.mp3' => 'private/audio/sample-audio-1.mp3',
            'audio/sample-audio-2.mp3' => 'private/audio/sample-audio-2.mp3',
            'videos/sample-video-1.mp4' => 'private/videos/sample-video-1.mp4',
            'videos/sample-video-2.mp4' => 'private/videos/sample-video-2.mp4',
        ];

        foreach ($fixtureFiles as $fixturePath => $storagePath) {
            $source = base_path("tests/fixtures/{$fixturePath}");
            $destination = storage_path("app/{$storagePath}");

            if (file_exists($source)) {
                // Copy file from fixtures to storage
                copy($source, $destination);
            }
        }

        // Local files (with path, no URL)
        $localFiles = [
            [
                'source' => 'local',
                'filename' => 'sample-image-1.jpg',
                'ext' => 'jpg',
                'path' => 'private/images/sample-image-1.jpg',
                'url' => null,
                'mime_type' => 'image/jpeg',
                'title' => 'Local Sample Image 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-image-2.jpg',
                'ext' => 'jpg',
                'path' => 'private/images/sample-image-2.jpg',
                'url' => null,
                'mime_type' => 'image/jpeg',
                'title' => 'Local Sample Image 2',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-audio-1.mp3',
                'ext' => 'mp3',
                'path' => 'private/audio/sample-audio-1.mp3',
                'url' => null,
                'mime_type' => 'audio/mpeg',
                'title' => 'Local Sample Audio 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-audio-2.mp3',
                'ext' => 'mp3',
                'path' => 'private/audio/sample-audio-2.mp3',
                'url' => null,
                'mime_type' => 'audio/mpeg',
                'title' => 'Local Sample Audio 2',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-video-1.mp4',
                'ext' => 'mp4',
                'path' => 'private/videos/sample-video-1.mp4',
                'url' => null,
                'mime_type' => 'video/mp4',
                'title' => 'Local Sample Video 1',
                'downloaded' => false,
            ],
            [
                'source' => 'local',
                'filename' => 'sample-video-2.mp4',
                'ext' => 'mp4',
                'path' => 'private/videos/sample-video-2.mp4',
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
        $downloadedFiles = [
            [
                'source' => 'YouTube',
                'filename' => 'big-buck-bunny-downloaded.mp4',
                'ext' => 'mp4',
                'path' => 'private/videos/sample-video-1.mp4', // Reuse existing file
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Big Buck Bunny - Downloaded',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(5),
            ],
            [
                'source' => 'YouTube',
                'filename' => 'elephants-dream-downloaded.mp4',
                'ext' => 'mp4',
                'path' => 'private/videos/sample-video-2.mp4', // Reuse existing file
                'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ElephantsDream.mp4',
                'mime_type' => 'video/mp4',
                'title' => 'Elephants Dream - Downloaded',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(3),
            ],
            [
                'source' => 'Booru',
                'filename' => 'downloaded-image-1.jpg',
                'ext' => 'jpg',
                'path' => 'private/images/sample-image-1.jpg', // Reuse existing file
                'url' => 'https://picsum.photos/1920/1080',
                'mime_type' => 'image/jpeg',
                'title' => 'Downloaded Image 1',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(7),
            ],
            [
                'source' => 'Booru',
                'filename' => 'downloaded-image-2.jpg',
                'ext' => 'jpg',
                'path' => 'private/images/sample-image-2.jpg', // Reuse existing file
                'url' => 'https://picsum.photos/1600/900',
                'mime_type' => 'image/jpeg',
                'title' => 'Downloaded Image 2',
                'downloaded' => true,
                'downloaded_at' => now()->subDays(2),
            ],
        ];

        // Process local files
        foreach ($localFiles as $fileData) {
            $fullPath = storage_path('app/'.$fileData['path']);
            $size = file_exists($fullPath) ? filesize($fullPath) : null;

            File::create(array_merge($fileData, [
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
        foreach ($downloadedFiles as $fileData) {
            $fullPath = storage_path('app/'.$fileData['path']);
            $size = file_exists($fullPath) ? filesize($fullPath) : null;

            File::create(array_merge($fileData, [
                'size' => $size,
                'created_at' => now()->subDays(rand(1, 30)),
            ]));
        }
    }
}
