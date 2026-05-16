<?php

namespace Database\Seeders;

use App\Models\File;
use App\Models\FileMetadata;
use App\Support\AtlasStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class AudioDevelopmentSeeder extends Seeder
{
    private const int COMMON_RECORDS = 950;

    private const int EDGE_RECORDS = 50;

    private const string PRIMARY_FIXTURE = 'audio/sample-audio-1.mp3';

    private const string SECONDARY_FIXTURE = 'audio/sample-audio-2.mp3';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $primaryAudio = $this->ensureFixtureInAtlasStorage(self::PRIMARY_FIXTURE, 'atlas-dev-audio-primary.mp3');
        $secondaryAudio = $this->ensureFixtureInAtlasStorage(self::SECONDARY_FIXTURE, 'atlas-dev-audio-edge.mp3');

        for ($number = 1; $number <= self::COMMON_RECORDS + self::EDGE_RECORDS; $number++) {
            $isEdgeCase = $number > self::COMMON_RECORDS;
            $audioFixture = $isEdgeCase ? $secondaryAudio : $primaryAudio;
            $profile = $this->profileFor($number, $isEdgeCase);

            $file = File::query()->updateOrCreate(
                ['source_id' => $this->sourceId($number)],
                [
                    'source' => $profile['source'],
                    'url' => $profile['url'],
                    'url_hash' => is_string($profile['url']) ? hash('sha256', $profile['url']) : null,
                    'referrer_url' => $profile['referrer_url'],
                    'referrer_url_hash' => is_string($profile['referrer_url']) ? hash('sha256', $profile['referrer_url']) : null,
                    'path' => $audioFixture['path'],
                    'filename' => $profile['filename'],
                    'ext' => 'mp3',
                    'size' => $audioFixture['size'],
                    'mime_type' => 'audio/mpeg',
                    'hash' => $audioFixture['hash'],
                    'title' => $profile['title'],
                    'description' => $profile['description'],
                    'tags' => $profile['tags'],
                    'listing_metadata' => $profile['listing_metadata'],
                    'detail_metadata' => $profile['detail_metadata'],
                    'downloaded' => $profile['downloaded'],
                    'downloaded_at' => $profile['downloaded'] ? now()->subDays($number % 90) : null,
                    'imported_at' => $profile['downloaded'] ? null : now()->subDays($number % 90),
                    'download_progress' => 100,
                    'not_found' => false,
                    'blacklisted_at' => null,
                ],
            );

            FileMetadata::query()->updateOrCreate(
                ['file_id' => $file->id],
                [
                    'payload' => $profile['payload'],
                    'is_review_required' => $isEdgeCase && $number % 9 === 0,
                    'is_extracted' => true,
                ],
            );
        }
    }

    /**
     * @return array{path:string, hash:string, size:int|null}
     */
    private function ensureFixtureInAtlasStorage(string $fixturePath, string $storedFilename): array
    {
        $sourcePath = base_path("tests/fixtures/{$fixturePath}");
        if (! is_file($sourcePath)) {
            throw new \RuntimeException("Audio fixture file not found: {$fixturePath}");
        }

        $hash = hash_file('sha256', $sourcePath);
        $atlasStorage = new AtlasStorage;
        $disk = Storage::disk(AtlasStorage::DISK);
        $path = $atlasStorage->segmentedPath(AtlasStorage::IMPORTS, $storedFilename, $hash);

        if (! $disk->exists($path)) {
            $stream = fopen($sourcePath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException("Unable to open audio fixture file: {$fixturePath}");
            }

            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }
        }

        $diskSize = $disk->size($path);
        $fixtureSize = @filesize($sourcePath);
        $size = is_int($diskSize) ? $diskSize : (is_int($fixtureSize) ? $fixtureSize : null);

        return [
            'path' => $path,
            'hash' => $hash,
            'size' => $size,
        ];
    }

    /**
     * @return array{
     *     source:string,
     *     url:string|null,
     *     referrer_url:string|null,
     *     filename:string,
     *     title:string|null,
     *     description:string,
     *     tags:list<string>,
     *     listing_metadata:array<string, mixed>,
     *     detail_metadata:array<string, mixed>,
     *     payload:array<string, mixed>,
     *     downloaded:bool
     * }
     */
    private function profileFor(int $number, bool $isEdgeCase): array
    {
        $source = $this->sourceFor($number);
        $artist = $this->artistFor($number);
        $album = $this->albumFor($number);
        $title = $isEdgeCase ? $this->edgeTitleFor($number) : sprintf('Atlas Seed Track %04d', $number);
        $duration = $isEdgeCase ? 15 + ($number % 840) : 90 + ($number % 360);
        $filename = sprintf('atlas-dev-audio-%04d.mp3', $number);
        $downloaded = $source !== 'local';

        $payload = [
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'duration_seconds' => $duration,
            'bitrate' => [128000, 192000, 256000, 320000][$number % 4],
            'sample_rate' => [44100, 48000][$number % 2],
            'channels' => $number % 7 === 0 ? 1 : 2,
            'genre' => ['ambient', 'electronic', 'field recording', 'spoken word', 'test tone'][$number % 5],
            'year' => 1995 + ($number % 31),
            'track' => ($number % 18) + 1,
            'disc' => ($number % 3) + 1,
            'bpm' => 70 + ($number % 110),
        ];

        $payload = $this->applyPayloadVariation($payload, $artist, $album, $number, $isEdgeCase);

        return [
            'source' => $source,
            'url' => $downloaded ? $this->urlFor($source, $number) : null,
            'referrer_url' => $downloaded ? "https://audio.example.test/library/{$number}" : null,
            'filename' => $filename,
            'title' => $title,
            'description' => $isEdgeCase
                ? 'Edge-case seeded audio record for player and metadata UI development.'
                : 'Seeded audio record for player and list development.',
            'tags' => ['audio-dev', $source, $payload['genre']],
            'listing_metadata' => [
                'seed' => 'audio-development',
                'seed_number' => $number,
                'duration_seconds' => $duration,
                'source_label' => ucfirst($source),
            ],
            'detail_metadata' => [
                'seed' => 'audio-development',
                'edge_case' => $isEdgeCase,
                'fixture' => $isEdgeCase ? self::SECONDARY_FIXTURE : self::PRIMARY_FIXTURE,
            ],
            'payload' => $payload,
            'downloaded' => $downloaded,
        ];
    }

    private function sourceId(int $number): string
    {
        return sprintf('atlas-dev-audio-%04d', $number);
    }

    private function sourceFor(int $number): string
    {
        return match ($number % 10) {
            0, 1, 2, 6, 7 => 'local',
            3, 4, 5 => 'spotify',
            8 => 'Bandcamp',
            default => 'Podcast',
        };
    }

    private function artistFor(int $number): string
    {
        $artists = [
            'North Window',
            'Signal Park',
            'The Quiet Machines',
            'Mira Vale',
            'Low Lanterns',
            'Archive Drift',
            'Studio Orchard',
            'River Console',
        ];

        return $artists[$number % count($artists)];
    }

    private function albumFor(int $number): string
    {
        $albums = [
            'Late Indexes',
            'Playback Notes',
            'Blue Room Sessions',
            'Filed Under Motion',
            'Drafts for Headphones',
            'The Seeded Collection',
        ];

        return $albums[$number % count($albums)];
    }

    private function edgeTitleFor(int $number): ?string
    {
        return match ($number % 8) {
            0 => '',
            1 => 'A very long seeded audio title that should force truncation and still keep the player surface stable '.$number,
            2 => 'Track with punctuation, brackets, commas, and version tags (remaster, take 4) '.$number,
            3 => null,
            default => sprintf('Atlas Edge Audio %04d', $number),
        };
    }

    private function urlFor(string $source, int $number): string
    {
        if ($source === 'spotify') {
            return sprintf('https://open.spotify.com/track/atlas-dev-audio-%04d', $number);
        }

        return sprintf('https://audio.example.test/%s/atlas-dev-audio-%04d.mp3', strtolower($source), $number);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPayloadVariation(array $payload, string $artist, string $album, int $number, bool $isEdgeCase): array
    {
        match ($number % 6) {
            0 => $payload['artists'] = [$artist, 'Guest '.($number % 12)],
            1 => $payload['artist'] = "{$artist}; Collaborator ".($number % 7),
            2 => $payload['album_artist'] = $artist.' Collective',
            3 => $payload['performer'] = $artist.' Live',
            4 => $payload['albums'] = [$album, $album.' Deluxe'],
            default => null,
        };

        if (! $isEdgeCase) {
            return $payload;
        }

        match ($number % 10) {
            0 => $payload['artist'] = '',
            1 => $payload['artists'] = [$artist, $artist, ''],
            2 => $payload['album'] = '',
            3 => $payload['album_artist'] = ['Various Artists', $artist],
            4 => $payload['performer'] = ['Live Desk', 'Archive Transfer'],
            5 => $payload['album'] = $album.', alternate metadata album',
            6 => $payload['duration_seconds'] = 0,
            7 => $payload['title'] = null,
            8 => $payload['bitrate'] = null,
            default => $payload['artist'] = "{$artist} | {$artist} Side Project",
        };

        return $payload;
    }
}
