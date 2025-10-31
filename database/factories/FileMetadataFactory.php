<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\FileMetadata;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class FileMetadataFactory extends Factory
{
    protected $model = FileMetadata::class;

    public function definition(): array
    {
        return [
            'payload' => $this->faker->words(),
            'is_review_required' => $this->faker->boolean(),
            'is_extracted' => $this->faker->boolean(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),

            'file_id' => File::factory(),
        ];
    }
}
