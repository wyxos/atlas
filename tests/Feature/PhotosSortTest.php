<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

use function Pest\Laravel\actingAs;

it('returns photos feed sorted by oldest when requested', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    actingAs($user);

    $oldest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/oldest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/oldest-thumb.jpg',
        'downloaded_at' => Carbon::now()->subDays(3),
        'created_at' => Carbon::now()->subDays(3),
    ]);

    $middle = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/middle.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/middle-thumb.jpg',
        'downloaded_at' => Carbon::now()->subDay(),
        'created_at' => Carbon::now()->subDay(),
    ]);

    $newest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/newest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/newest-thumb.jpg',
        'downloaded_at' => Carbon::now(),
        'created_at' => Carbon::now(),
    ]);

    withFakeScoutResults([$newest, $middle, $oldest], function () use ($oldest, $middle, $newest) {
        $response = $this->getJson(route('photos.data', [
            'sort' => 'oldest',
            'limit' => 10,
        ]));

        $response->assertStatus(200);

        expect($response->json('filter.sort'))->toBe('oldest');
        expect($response->json('files.*.id'))->toEqual([
            $oldest->id,
            $middle->id,
            $newest->id,
        ]);
    });
});

it('returns disliked photos sorted by oldest when requested', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    actingAs($user);

    $oldest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/disliked-oldest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/disliked-oldest-thumb.jpg',
        'blacklisted_at' => Carbon::now()->subDays(5),
        'created_at' => Carbon::now()->subDays(5),
    ]);

    $middle = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/disliked-middle.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/disliked-middle-thumb.jpg',
        'blacklisted_at' => Carbon::now()->subDays(2),
        'created_at' => Carbon::now()->subDays(2),
    ]);

    $newest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/disliked-newest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/disliked-newest-thumb.jpg',
        'blacklisted_at' => Carbon::now()->subDay(),
        'created_at' => Carbon::now()->subDay(),
    ]);

    withFakeScoutResults([$newest, $middle, $oldest], function () use ($oldest, $middle, $newest) {
        $response = $this->getJson(route('photos.disliked.data', [
            'category' => 'all',
            'sort' => 'oldest',
            'limit' => 10,
        ]));

        $response->assertStatus(200);

        expect($response->json('filter.sort'))->toBe('oldest');
        expect($response->json('files.*.id'))->toEqual([
            $oldest->id,
            $middle->id,
            $newest->id,
        ]);
    });
});

it('returns unrated photos sorted by oldest when requested', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    actingAs($user);

    $oldest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/unrated-oldest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/unrated-oldest-thumb.jpg',
        'not_found' => false,
        'created_at' => Carbon::now()->subDays(4),
    ]);

    $middle = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/unrated-middle.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/unrated-middle-thumb.jpg',
        'not_found' => false,
        'created_at' => Carbon::now()->subDays(2),
    ]);

    $newest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'url' => 'https://cdn.example.com/photos/unrated-newest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/unrated-newest-thumb.jpg',
        'not_found' => false,
        'created_at' => Carbon::now()->subDay(),
    ]);

    withFakeScoutResults([$newest, $middle, $oldest], function () use ($oldest, $middle, $newest) {
        $response = $this->getJson(route('photos.unrated.data', [
            'sort' => 'oldest',
            'limit' => 10,
        ]));

        $response->assertStatus(200);

        expect($response->json('filter.sort'))->toBe('oldest');
        expect($response->json('files.*.id'))->toEqual([
            $oldest->id,
            $middle->id,
            $newest->id,
        ]);
    });
});

it('returns reaction photos sorted by oldest when requested', function () {
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', false);
    Config::set('scout.after_commit', false);

    $user = User::factory()->create();
    actingAs($user);

    $oldest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/reactions-oldest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/reactions-oldest-thumb.jpg',
        'downloaded_at' => Carbon::now()->subDays(6),
        'created_at' => Carbon::now()->subDays(6),
    ]);

    $middle = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/reactions-middle.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/reactions-middle-thumb.jpg',
        'downloaded_at' => Carbon::now()->subDays(3),
        'created_at' => Carbon::now()->subDays(3),
    ]);

    $newest = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/reactions-newest.jpg',
        'thumbnail_url' => 'https://cdn.example.com/photos/reactions-newest-thumb.jpg',
        'downloaded_at' => Carbon::now(),
        'created_at' => Carbon::now(),
    ]);

    Reaction::factory()->create([
        'file_id' => $oldest->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Reaction::factory()->create([
        'file_id' => $middle->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Reaction::factory()->create([
        'file_id' => $newest->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    withFakeScoutResults([$newest, $middle, $oldest], function () use ($oldest, $middle, $newest) {
        $response = $this->getJson(route('photos.reactions.data', [
            'kind' => 'liked',
            'sort' => 'oldest',
            'limit' => 10,
        ]));

        $response->assertStatus(200);

        expect($response->json('filter.sort'))->toBe('oldest');
        expect($response->json('files.*.id'))->toEqual([
            $oldest->id,
            $middle->id,
            $newest->id,
        ]);
        expect($response->json('filter.rand_seed'))->toBeNull();
    });
});
