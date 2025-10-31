<?php

namespace Database\Factories;

use App\Models\ModerationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModerationRule>
 */
class ModerationRuleFactory extends Factory
{
    protected $model = ModerationRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'active' => true,
            'nsfw' => false,
            'op' => 'any',
            'terms' => ['car'],
            'min' => null,
            'options' => ['case_sensitive' => false, 'whole_word' => true],
            'children' => null,
        ];
    }

    public function any(array $terms): self
    {
        return $this->state(fn () => ['op' => 'any', 'terms' => array_values($terms), 'children' => null]);
    }

    public function all(array $terms): self
    {
        return $this->state(fn () => ['op' => 'all', 'terms' => array_values($terms), 'children' => null]);
    }

    public function notAny(array $terms): self
    {
        return $this->state(fn () => ['op' => 'not_any', 'terms' => array_values($terms), 'children' => null]);
    }

    public function atLeast(int $min, array $terms): self
    {
        return $this->state(fn () => ['op' => 'at_least', 'min' => $min, 'terms' => array_values($terms), 'children' => null]);
    }

    public function and(array $children): self
    {
        return $this->state(fn () => ['op' => 'and', 'terms' => null, 'children' => $children]);
    }

    public function or(array $children): self
    {
        return $this->state(fn () => ['op' => 'or', 'terms' => null, 'children' => $children]);
    }

    public function nsfw(): self
    {
        return $this->state(fn () => ['nsfw' => true]);
    }

    public function sfw(): self
    {
        return $this->state(fn () => ['nsfw' => false]);
    }
}
