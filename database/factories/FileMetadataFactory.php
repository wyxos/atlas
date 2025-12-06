<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FileMetadata>
 */
class FileMetadataFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'payload' => [
                'width' => fake()->numberBetween(100, 4000),
                'height' => fake()->numberBetween(100, 4000),
            ],
            'is_review_required' => false,
            'is_extracted' => true,
        ];
    }
}
