<?php

namespace Database\Factories;

use App\Models\Album;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Album>
 */
class AlbumFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'release_label' => null,
            'catalog_number' => null,
            'barcode' => null,
            'release_date' => null,
            'release_country' => null,
            'musicbrainz_release_id' => null,
            'discogs_release_id' => null,
        ];
    }
}
