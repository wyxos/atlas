<?php

namespace Database\Factories;

use App\Models\Container;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Container>
 */
class ContainerFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Container::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => $this->faker->randomElement(['post', 'gallery', 'collection']),
            'source' => $this->faker->randomElement(['CivitAI', 'DeviantArt', 'ArtStation']),
            'source_id' => $this->faker->unique()->numberBetween(1, 999999),
            'referrer' => $this->faker->url(),
        ];
    }

    /**
     * Indicate that the container is a post type.
     */
    public function post(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'post',
        ]);
    }

    /**
     * Indicate that the container is from CivitAI.
     */
    public function civitai(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'CivitAI',
            'referrer' => 'https://civitai.com/posts/' . $attributes['source_id'],
        ]);
    }
}
