<?php

use App\Models\Container;
use App\Models\File;
use App\Services\BrowsePersister;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('attaches DeviantArt files to existing user containers with normalized username casing', function () {
    $existingContainer = Container::query()->create([
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'animeaivideos',
        'referrer' => 'https://www.deviantart.com/animeaivideos/gallery',
    ]);
    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'url' => 'https://fc.example.test/animeaivideos.jpg',
        'listing_metadata' => [
            'user_container_source' => 'deviantart.com',
            'user_container_source_id' => 'AnimeAIVideos',
            'user_container_referrer_url' => 'https://www.deviantart.com/AnimeAIVideos/gallery',
        ],
    ]);

    (new BrowsePersister)->attachContainersForFiles(collect([$file]));

    $this->assertDatabaseCount('containers', 1);
    expect($file->fresh()?->containers()->where('containers.id', $existingContainer->id)->exists())->toBeTrue();
});
