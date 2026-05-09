<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sources = ['local', 'NAS', 'YouTube', 'Booru'];
        $mimeTypes = [
            'image/jpeg',
            'image/png',
            'video/mp4',
            'video/webm',
            'audio/mpeg',
            'audio/ogg',
        ];
        $extensions = ['jpg', 'png', 'mp4', 'webm', 'mp3', 'ogg'];
        $mimeType = fake()->randomElement($mimeTypes);
        $ext = fake()->randomElement($extensions);

        $pathToken = fake()->optional()->uuid();
        $previewPathToken = fake()->optional()->uuid();
        $posterPathToken = fake()->optional()->uuid();
        $pathHash = $pathToken ? str_replace('-', '', $pathToken) : null;
        $path = $pathToken
            ? 'downloads/'.substr($pathHash, 0, 2).'/'.substr($pathHash, 2, 2).'/'.$pathToken.'.'.$ext
            : null;
        $assetDirectory = $path ? dirname($path).'/preview' : null;

        $url = fake()->unique()->url();
        $referrerUrl = fake()->url();

        return [
            'source' => fake()->randomElement($sources),
            'source_id' => fake()->optional()->uuid(),
            'url' => $url,
            // Provenance page URL is now non-unique.
            'referrer_url' => $referrerUrl,
            // Avoid Faker's filePath() (tempnam) which can emit warnings that PHPUnit treats as exceptions.
            'path' => $path,
            'filename' => fake()->word().'.'.$ext,
            'ext' => $ext,
            'size' => fake()->numberBetween(1024, 104857600), // 1KB to 100MB
            'mime_type' => $mimeType,
            'hash' => fake()->optional()->sha256(),
            'title' => fake()->optional()->sentence(),
            'description' => fake()->optional()->paragraph(),
            'preview_url' => fake()->optional()->imageUrl(),
            'preview_path' => $assetDirectory && $previewPathToken ? $assetDirectory.'/'.$previewPathToken.'.jpg' : null,
            'poster_path' => $assetDirectory && $posterPathToken ? $assetDirectory.'/'.$posterPathToken.'.jpg' : null,
            'tags' => fake()->optional()->randomElements(['tag1', 'tag2', 'tag3', 'tag4'], 2),
            'parent_id' => null,
            'chapter' => null,
            'previewed_at' => null,
            'previewed_count' => 0,
            'seen_at' => null,
            'seen_count' => 0,
            'blacklisted_at' => null,
            'not_found' => false,
            'listing_metadata' => null,
            'detail_metadata' => null,
            'downloaded' => fake()->boolean(),
            'downloaded_at' => fake()->optional()->dateTime(),
            'imported_at' => null,
            'download_progress' => 0,
        ];
    }
}
