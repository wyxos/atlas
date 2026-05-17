<?php

namespace Database\Factories;

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlbumCover>
 */
class AlbumCoverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'imports/'.fake()->lexify('??').'/'.fake()->lexify('??').'/covers/'.fake()->uuid().'.jpg';

        return [
            'album_id' => Album::factory(),
            'file_id' => File::factory(),
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'hash' => fake()->sha256(),
            'size' => fake()->numberBetween(1024, 1048576),
            'mime_type' => 'image/jpeg',
            'picture_type' => null,
            'sort_order' => 0,
            'is_default' => true,
        ];
    }
}
