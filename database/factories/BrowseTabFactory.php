<?php

namespace Database\Factories;

use App\Models\BrowseTab;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BrowseTab>
 */
class BrowseTabFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->words(2, true),
            'query_params' => [
                'page' => fake()->numberBetween(1, 100),
                'next' => fake()->optional()->numerify('###|##########'),
            ],
            'file_ids' => [],
            'position' => 0,
        ];
    }

    /**
     * Set the query params with page and next.
     */
    public function withQueryParams(array $queryParams): static
    {
        return $this->state(fn (array $attributes) => [
            'query_params' => $queryParams,
        ]);
    }

    /**
     * Set the file IDs (referrer URLs).
     */
    public function withFileIds(array $fileIds): static
    {
        return $this->state(fn (array $attributes) => [
            'file_ids' => $fileIds,
        ]);
    }
}
