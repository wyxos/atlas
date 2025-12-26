<?php

namespace Database\Factories;

use App\Models\ModerationRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ModerationRule>
 */
class ModerationRuleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<\Illuminate\Database\Eloquent\Model>
     */
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

    /**
     * Set the operation to 'any' with the given terms.
     */
    public function any(array $terms): self
    {
        return $this->state(fn () => ['op' => 'any', 'terms' => array_values($terms), 'children' => null]);
    }

    /**
     * Set the operation to 'all' with the given terms.
     */
    public function all(array $terms): self
    {
        return $this->state(fn () => ['op' => 'all', 'terms' => array_values($terms), 'children' => null]);
    }

    /**
     * Set the operation to 'not_any' with the given terms.
     */
    public function notAny(array $terms): self
    {
        return $this->state(fn () => ['op' => 'not_any', 'terms' => array_values($terms), 'children' => null]);
    }

    /**
     * Set the operation to 'at_least' with the given minimum and terms.
     */
    public function atLeast(int $min, array $terms): self
    {
        return $this->state(fn () => ['op' => 'at_least', 'min' => $min, 'terms' => array_values($terms), 'children' => null]);
    }

    /**
     * Set the operation to 'and' with the given children.
     */
    public function and(array $children): self
    {
        return $this->state(fn () => ['op' => 'and', 'terms' => null, 'children' => $children]);
    }

    /**
     * Set the operation to 'or' with the given children.
     */
    public function or(array $children): self
    {
        return $this->state(fn () => ['op' => 'or', 'terms' => null, 'children' => $children]);
    }

    /**
     * Set the rule as NSFW.
     */
    public function nsfw(): self
    {
        return $this->state(fn () => ['nsfw' => true]);
    }

    /**
     * Set the rule as SFW.
     */
    public function sfw(): self
    {
        return $this->state(fn () => ['nsfw' => false]);
    }
}
