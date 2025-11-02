<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reaction>
 */
class ReactionFactory extends Factory
{
    protected $model = Reaction::class;

    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement(['love', 'like', 'dislike', 'funny']),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
