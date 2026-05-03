<?php

namespace Database\Factories;

use App\Enums\BlacklistPreviewedCountMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Container>
 */
class ContainerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['user', 'post', 'manga', 'gallery'];
        $sources = ['CivitAI', 'Wallhaven', 'Booru', 'Local'];

        return [
            'type' => fake()->randomElement($types),
            'source' => fake()->randomElement($sources),
            'source_id' => fake()->uuid(),
            'referrer' => fake()->optional()->url(),
            'blacklist_previewed_count_mode' => BlacklistPreviewedCountMode::PRESERVE,
        ];
    }
}
