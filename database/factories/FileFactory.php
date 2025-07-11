<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected $model = File::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sources = ['YouTube', 'NAS', 'Booru', 'Reddit', 'Twitter'];
        $extensions = ['mp4', 'mkv', 'avi', 'jpg', 'png', 'gif', 'webm'];
        $mimeTypes = [
            'mp4' => 'video/mp4',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webm' => 'video/webm',
        ];

        $ext = $this->faker->randomElement($extensions);
        $filename = $this->faker->word().'-'.$this->faker->word().'.'.$ext;

        return [
            'source' => $this->faker->randomElement($sources),
            'source_id' => $this->faker->optional()->regexify('[A-Za-z0-9]{10}'),
            'url' => $this->faker->optional()->url(),
            'referrer_url' => $this->faker->optional()->url(),
            'path' => $this->faker->optional()->filePath(),
            'filename' => $filename,
            'ext' => $ext,
            'size' => $this->faker->optional()->numberBetween(1024, 1024 * 1024 * 100), // 1KB to 100MB
            'mime_type' => $mimeTypes[$ext] ?? 'application/octet-stream',
            'hash' => $this->faker->optional()->sha256(),
            'title' => $this->faker->optional()->sentence(3),
            'description' => $this->faker->optional()->paragraph(),
            'thumbnail_url' => $this->faker->optional()->imageUrl(),
            'tags' => $this->faker->optional()->randomElements([
                'funny', 'cute', 'anime', 'gaming', 'music', 'movie', 'series',
                'documentary', 'tutorial', 'meme', 'art', 'nature', 'travel',
            ], $this->faker->numberBetween(0, 5)),
            'parent_id' => null, // Can be overridden in tests
            'chapter' => $this->faker->optional()->randomElement(['Chapter 1', 'Episode 1', 'Part 1']),
            'seen_preview_at' => $this->faker->optional()->dateTimeBetween('-1 month'),
            'seen_file_at' => $this->faker->optional()->dateTimeBetween('-1 month'),
            'is_blacklisted' => $this->faker->boolean(10), // 10% chance of being blacklisted
            'blacklist_reason' => function (array $attributes) {
                return $attributes['is_blacklisted'] ? $this->faker->sentence() : null;
            },
            'liked' => $this->faker->boolean(30), // 30% chance of being liked
            'liked_at' => function (array $attributes) {
                return $attributes['liked'] ? $this->faker->dateTimeBetween('-1 month') : null;
            },
            'disliked' => $this->faker->boolean(15), // 15% chance of being disliked
            'disliked_at' => function (array $attributes) {
                return $attributes['disliked'] ? $this->faker->dateTimeBetween('-1 month') : null;
            },
            'loved' => $this->faker->boolean(20), // 20% chance of being loved
            'loved_at' => function (array $attributes) {
                return $attributes['loved'] ? $this->faker->dateTimeBetween('-1 month') : null;
            },
            'funny' => $this->faker->boolean(10), // 10% chance of being funny
            'laughed_at' => function (array $attributes) {
                return $attributes['funny'] ? $this->faker->dateTimeBetween('-1 month') : null;
            },
            'downloaded' => $this->faker->boolean(40), // 40% chance of being downloaded
            'download_progress' => function (array $attributes) {
                return $attributes['downloaded'] ? 100 : $this->faker->numberBetween(0, 99);
            },
            'downloaded_at' => function (array $attributes) {
                return $attributes['downloaded'] && $attributes['download_progress'] === 100
                    ? $this->faker->dateTimeBetween('-1 month')
                    : null;
            },
        ];
    }

    /**
     * Indicate that the file is liked.
     */
    public function liked(): static
    {
        return $this->state(fn (array $attributes) => [
            'liked' => true,
            'liked_at' => $this->faker->dateTimeBetween('-1 month'),
        ]);
    }

    /**
     * Indicate that the file is loved.
     */
    public function loved(): static
    {
        return $this->state(fn (array $attributes) => [
            'loved' => true,
            'loved_at' => $this->faker->dateTimeBetween('-1 month'),
        ]);
    }

    /**
     * Indicate that the file is downloaded.
     */
    public function downloaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'downloaded' => true,
            'download_progress' => 100,
            'downloaded_at' => $this->faker->dateTimeBetween('-1 month'),
        ]);
    }

    /**
     * Indicate that the file is blacklisted.
     */
    public function blacklisted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_blacklisted' => true,
            'blacklist_reason' => $this->faker->sentence(),
        ]);
    }

    /**
     * Create a video file.
     */
    public function video(): static
    {
        $videoExtensions = ['mp4', 'mkv', 'avi', 'webm'];
        $ext = $this->faker->randomElement($videoExtensions);

        return $this->state(fn (array $attributes) => [
            'ext' => $ext,
            'filename' => $this->faker->word().'-video.'.$ext,
            'mime_type' => match ($ext) {
                'mp4' => 'video/mp4',
                'mkv' => 'video/x-matroska',
                'avi' => 'video/x-msvideo',
                'webm' => 'video/webm',
                default => 'video/mp4',
            },
            'size' => $this->faker->numberBetween(1024 * 1024 * 10, 1024 * 1024 * 500), // 10MB to 500MB
        ]);
    }

    /**
     * Create an image file.
     */
    public function image(): static
    {
        $imageExtensions = ['jpg', 'png', 'gif'];
        $ext = $this->faker->randomElement($imageExtensions);

        return $this->state(fn (array $attributes) => [
            'ext' => $ext,
            'filename' => $this->faker->word().'-image.'.$ext,
            'mime_type' => match ($ext) {
                'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                default => 'image/jpeg',
            },
            'size' => $this->faker->numberBetween(1024 * 50, 1024 * 1024 * 10), // 50KB to 10MB
        ]);
    }
}
