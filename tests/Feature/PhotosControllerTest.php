<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Scout\EngineManager;

beforeEach(function () {
    $this->originalScoutDriver = config('scout.driver');

    $this->fakeTypesense = new FakeTypesenseEngine;

    resolve(EngineManager::class)->extend('fake-typesense', function () {
        return $this->fakeTypesense;
    });

    config()->set('scout.driver', 'fake-typesense');
});

afterEach(function () {
    config()->set('scout.driver', $this->originalScoutDriver);
});

it('returns the newest files excluding disliked files when sorted by newest', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $seededAt = Carbon::now();

    $files = collect();
    $documents = [];

    for ($index = 0; $index < 25; $index++) {
        /** @var File $file */
        $file = File::factory()->create([
            'mime_type' => 'image/jpeg',
            'path' => 'local/path-'.$index,
            'downloaded_at' => $seededAt->copy()->subMinutes($index),
        ]);

        if ($index < 5) {
            Reaction::factory()->create([
                'file_id' => $file->id,
                'user_id' => $user->id,
                'type' => 'dislike',
            ]);
        }

        $files->push($file->fresh());

        $documents[] = [
            'id' => (string) $file->id,
            'mime_group' => 'image',
            'source' => 'local',
            'has_reactions' => $index < 5,
            'downloaded_at' => $file->downloaded_at?->timestamp ?? 0,
            'created_at' => $file->created_at?->timestamp ?? 0,
            'dislike_user_ids' => $index < 5 ? [(string) $user->id] : [],
        ];
    }

    $this->fakeTypesense->setDocuments($documents);

    $response = $this->getJson(route('photos.data', [
        'sort' => 'newest',
        'limit' => 20,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = $payload['files'];

    expect($filesPayload)->toHaveCount(20);

    $dislikedIds = collect($documents)
        ->filter(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    $returnedIds = collect($filesPayload)->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($returnedIds)->each(fn ($id) => expect($dislikedIds)->not->toContain($id));

    $expectedOrder = collect($documents)
        ->reject(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->sortByDesc('downloaded_at')
        ->take(20)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    expect($returnedIds)->toEqual($expectedOrder);
});

it('returns 20 unique random files when sorted by random', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $seededAt = Carbon::now();

    $documents = [];

    for ($index = 0; $index < 25; $index++) {
        /** @var File $file */
        $file = File::factory()->create([
            'mime_type' => 'image/jpeg',
            'path' => 'local/path-'.$index,
            'downloaded_at' => $seededAt->copy()->subMinutes($index),
        ]);

        if ($index < 3) {
            Reaction::factory()->create([
                'file_id' => $file->id,
                'user_id' => $user->id,
                'type' => 'dislike',
            ]);
        }

        $documents[] = [
            'id' => (string) $file->id,
            'mime_group' => 'image',
            'source' => 'local',
            'has_reactions' => $index < 3,
            'downloaded_at' => $file->downloaded_at?->timestamp ?? 0,
            'created_at' => $file->created_at?->timestamp ?? 0,
            'dislike_user_ids' => $index < 3 ? [(string) $user->id] : [],
        ];
    }

    $this->fakeTypesense->setDocuments($documents);

    $seed = 1337;

    $response = $this->getJson(route('photos.data', [
        'sort' => 'random',
        'limit' => 20,
        'rand_seed' => $seed,
    ]));

    $response->assertOk();

    $payload = $response->json();
    $filesPayload = collect($payload['files']);

    expect($filesPayload)->toHaveCount(20);
    expect($filesPayload->pluck('id')->duplicates())->toBeEmpty();

    $returnedIds = $filesPayload->pluck('id')->map(fn ($id) => (int) $id)->values();

    $dislikedIds = collect($documents)
        ->filter(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($returnedIds)->each(fn ($id) => expect($dislikedIds)->not->toContain($id));

    $expectedOrder = collect($documents)
        ->reject(fn ($doc) => in_array((string) $user->id, $doc['dislike_user_ids'], true))
        ->sortByDesc(fn ($doc) => crc32($seed.'|'.$doc['id']))
        ->take(20)
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->values();

    expect($returnedIds->all())->toEqual($expectedOrder->all());
    expect($payload['filter']['rand_seed'])->toBe($seed);
});
