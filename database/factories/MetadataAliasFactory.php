<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\MetadataAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetadataAlias>
 */
class MetadataAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aliasable_type' => File::class,
            'aliasable_id' => File::factory(),
            'field' => 'title',
            'value' => $this->faker->words(3, true),
            'kind' => 'search_alias',
            'locale' => null,
            'source' => 'test',
            'source_id' => null,
        ];
    }
}
