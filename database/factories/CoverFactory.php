<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Cover>
 */
class CoverFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => $this->faker->filePath(),
            'coverable_id' => $this->faker->numberBetween(1, 100),
            'coverable_type' => $this->faker->randomElement(['App\Models\Artist', 'App\Models\Album']),
            'hash' => $this->faker->md5(),
        ];
    }
}
