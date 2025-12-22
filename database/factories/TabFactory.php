<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tab>
 */
class TabFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->words(2, true),
            'query_params' => [
                'page' => fake()->numberBetween(1, 100),
                'next' => fake()->optional()->numerify('###|##########'),
            ],
            // file_ids removed - use files relationship instead
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
     * Attach files to the tab.
     */
    public function withFiles(array $fileIds): static
    {
        return $this->afterCreating(function ($tab) use ($fileIds) {
            $syncData = [];
            foreach ($fileIds as $index => $fileId) {
                $syncData[$fileId] = ['position' => $index];
            }
            $tab->files()->sync($syncData);
        });
    }
}

