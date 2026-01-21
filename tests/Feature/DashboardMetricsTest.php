<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('dashboard metrics report file and reaction totals', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    $manualBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => 'Manual review',
    ]);

    $autoBlacklisted = File::factory()->create([
        'blacklisted_at' => now(),
        'blacklist_reason' => null,
    ]);

    $unblacklisted = File::factory()->create();
    $unreacted = File::factory()->create();

    Reaction::create([
        'file_id' => $manualBlacklisted->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    Reaction::create([
        'file_id' => $manualBlacklisted->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    Reaction::create([
        'file_id' => $autoBlacklisted->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Reaction::create([
        'file_id' => $unblacklisted->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Reaction::create([
        'file_id' => $unblacklisted->id,
        'user_id' => $otherUser->id,
        'type' => 'dislike',
    ]);

    $response = $this->getJson('/api/dashboard/metrics');

    $response->assertSuccessful();
    $response->assertJson([
        'files' => [
            'total' => 4,
            'reactions' => [
                'love' => 1,
                'like' => 1,
                'dislike' => 1,
                'funny' => 1,
            ],
            'blacklisted' => [
                'total' => 2,
                'manual' => 1,
                'auto' => 1,
            ],
            'unreacted_not_blacklisted' => 1,
        ],
    ]);
});
