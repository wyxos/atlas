<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    // Force real Typesense via Scout
    Config::set('scout.driver', 'typesense');
    Config::set('scout.queue', false);
    // Use a unique prefix per test run to avoid stale schema conflicts
    $uniquePrefix = 'testing_'.Str::random(8);
    Config::set('scout.prefix', $uniquePrefix);
});

it('serves blacklisted but not-disliked files via Scout NOT-CONTAINS', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // A: blacklisted + disliked by user (excluded)
    $a = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'a.jpg',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'disliked',
    ]);
    Reaction::create(['user_id' => $user->id, 'file_id' => $a->id, 'type' => 'dislike']);
    $a->searchable();

    // B: blacklisted + liked by user (included)
    $b = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'b.jpg',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'disliked',
    ]);
    Reaction::create(['user_id' => $user->id, 'file_id' => $b->id, 'type' => 'like']);
    $b->searchable();

    // C: blacklisted + no reaction (included)
    $c = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'c.jpg',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'auto:meta_content',
    ]);
    $c->searchable();

    // Give Typesense a moment to index
    usleep(250000);

    $resp = $this->getJson(route('photos.disliked.data', ['category' => 'not-disliked', 'limit' => 50]));
    $resp->assertStatus(200);
    $ids = collect($resp->json('files'))->pluck('id')->all();

    expect($ids)->not->toContain($a->id);
    expect($ids)->toContain($b->id);
    expect($ids)->toContain($c->id);
})->group('typesense');

it('serves unrated files via Scout NOT-CONTAINS on reacted_user_ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // D: unrated (included)
    $d = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'd.jpg',
        'blacklisted_at' => null,
    ]);
    $d->searchable();

    // E: liked by user (excluded)
    $e = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'e.jpg',
        'blacklisted_at' => null,
    ]);
    Reaction::create(['user_id' => $user->id, 'file_id' => $e->id, 'type' => 'like']);
    $e->searchable();

    // F: funny by user (excluded)
    $f = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'f.jpg',
        'blacklisted_at' => null,
    ]);
    Reaction::create(['user_id' => $user->id, 'file_id' => $f->id, 'type' => 'funny']);
    $f->searchable();

    usleep(250000);

    $resp = $this->getJson(route('photos.unrated.data', ['limit' => 50]));
    $resp->assertStatus(200);
    $ids = collect($resp->json('files'))->pluck('id')->all();

    expect($ids)->toContain($d->id);
    expect($ids)->not->toContain($e->id);
    expect($ids)->not->toContain($f->id);
})->group('typesense');
