<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModerationRule>
 */
class ModerationRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(['contains', 'contains-combo']);
        
        $definition = [
            'name' => $this->faker->optional()->sentence(3),
            'type' => $type,
            'terms' => $this->faker->words(rand(1, 3)),
            'action' => $this->faker->randomElement(['block', 'flag', 'warn']),
            'active' => $this->faker->boolean(90),
            'description' => $this->faker->optional()->sentence(),
        ];

        if ($type === 'contains') {
            $definition['match'] = $this->faker->randomElement(['any', 'all']);
            $definition['unless'] = $this->faker->optional(30)->words(rand(1, 2));
        } elseif ($type === 'contains-combo') {
            $definition['with_terms'] = $this->faker->words(rand(1, 3));
        }

        return $definition;
    }

    public function containsType(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'contains',
            'match' => $this->faker->randomElement(['any', 'all']),
            'unless' => $this->faker->optional(40)->words(rand(1, 2)),
            'with_terms' => null,
        ]);
    }

    public function containsComboType(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'contains-combo',
            'match' => 'any',
            'unless' => null,
            'with_terms' => $this->faker->words(rand(1, 3)),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
