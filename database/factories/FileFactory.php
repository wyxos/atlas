<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \\Illuminate\\Database\\Eloquent\\Factories\\Factory<\\App\\Models\\File>
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
        $ext = fake()->randomElement(['mp3', 'wav', 'flac', 'mp4', 'webm', 'jpg', 'png']);
        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'flac' => 'audio/flac',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };

        return [
            'source' => 'local',
            'source_id' => null,
            'url' => null,
            'referrer_url' => null,
            'path' => null,
            'filename' => fake()->unique()->slug().'.'.$ext,
            'ext' => $ext,
            'size' => fake()->numberBetween(10_000, 50_000_000),
            'mime_type' => $mime,
            'hash' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(8),
            'thumbnail_url' => null,
            'tags' => null,
            'parent_id' => null,
            'chapter' => null,
            'previewed_at' => null,
            'seen_at' => null,
            'previewed_count' => 0,
            'seen_count' => 0,
            'blacklisted_at' => null,
            'blacklist_reason' => null,
            'downloaded' => false,
            'downloaded_at' => null,
            'download_progress' => 0,
            'not_found' => false,
        ];
    }
}
