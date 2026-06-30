<?php

use App\Events\AudioPlaybackSessionUpdated;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function playbackSessionTrack(array $overrides = []): array
{
    return [
        'id' => 41,
        'title' => 'Atlas Seed Track 0041',
        'source' => 'local',
        'sourceId' => null,
        'spotifyUri' => null,
        'artists' => 'Signal Park',
        'album' => 'Playback Notes',
        'coverUrl' => '/api/files/41/poster',
        'duration' => '1:31',
        'durationSeconds' => 91,
        'reaction' => null,
        'blacklistedAt' => null,
        'previewedCount' => 0,
        'seenCount' => 0,
        'playCount' => 0,
        'skipCount' => 0,
        'playbackUrl' => '/api/files/41/serve',
        ...$overrides,
    ];
}

function claimPlaybackSession(User $user, array $overrides = []): array
{
    $payload = [
        'instance_id' => 'pc-tab',
        'owner_label' => 'PC Chrome',
        'state' => 'playing',
        'source' => 'local',
        'current_track' => playbackSessionTrack(),
        'queue_label' => 'All audio',
        'position_seconds' => 12.4,
        'duration_seconds' => 91,
        'spotify_device_id' => null,
        ...$overrides,
    ];

    return test()->actingAs($user)
        ->postJson('/api/audio/playback-session/claim', $payload)
        ->assertSuccessful()
        ->json();
}

test('guest cannot read playback session', function () {
    $this->getJson('/api/audio/playback-session')->assertUnauthorized();
});

test('authenticated user gets an empty playback session when no owner exists', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/audio/playback-session');

    $response->assertSuccessful();
    $response->assertJsonPath('version', 0);
    $response->assertJsonPath('lease_token', null);
    $response->assertJsonPath('owner_instance_id', null);
    $response->assertJsonPath('state', 'idle');
    $response->assertJsonPath('current_track', null);
});

test('claim creates a user playback session and broadcasts it', function () {
    Event::fake();
    $user = User::factory()->create();

    $payload = claimPlaybackSession($user);

    expect($payload['version'])->toBe(1)
        ->and($payload['lease_token'])->toBeString()->not->toBe('')
        ->and($payload['owner_instance_id'])->toBe('pc-tab')
        ->and($payload['owner_label'])->toBe('PC Chrome')
        ->and($payload['state'])->toBe('playing')
        ->and($payload['source'])->toBe('local')
        ->and($payload['current_track']['id'])->toBe(41)
        ->and($payload['queue_label'])->toBe('All audio')
        ->and($payload['position_seconds'])->toBe(12.4)
        ->and($payload['duration_seconds'])->toBe(91)
        ->and($payload['spotify_device_id'])->toBeNull()
        ->and($payload['server_recorded_at_ms'])->toBeInt();

    Event::assertDispatched(AudioPlaybackSessionUpdated::class, function (AudioPlaybackSessionUpdated $event) use ($user, $payload): bool {
        $broadcast = $event->broadcastWith();

        return $event->userId === $user->id
            && $broadcast['version'] === $payload['version']
            && $broadcast['lease_token'] === $payload['lease_token']
            && $broadcast['owner_instance_id'] === 'pc-tab'
            && $broadcast['state'] === 'playing'
            && $broadcast['current_track']['id'] === 41
            && (float) $broadcast['position_seconds'] === 12.4
            && (float) $broadcast['duration_seconds'] === 91.0;
    });
});

test('heartbeat refreshes the owner lease and advances the session version', function () {
    $user = User::factory()->create();
    $claimed = claimPlaybackSession($user);

    $response = $this->actingAs($user)->postJson('/api/audio/playback-session/heartbeat', [
        'instance_id' => 'pc-tab',
        'lease_token' => $claimed['lease_token'],
        'state' => 'playing',
        'position_seconds' => 18.7,
        'duration_seconds' => 91,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('version', 2);
    $response->assertJsonPath('owner_instance_id', 'pc-tab');
    $response->assertJsonPath('position_seconds', 18.7);
    $response->assertJsonPath('duration_seconds', 91);
});

test('stale lease updates are rejected without replacing the active owner', function () {
    $user = User::factory()->create();
    $claimed = claimPlaybackSession($user);

    $response = $this->actingAs($user)->postJson('/api/audio/playback-session/update', [
        'instance_id' => 'mac-tab',
        'lease_token' => 'stale-token',
        'state' => 'paused',
        'position_seconds' => 44,
        'duration_seconds' => 91,
    ]);

    $response->assertConflict();
    $response->assertJsonPath('message', 'Playback ownership changed. Claim this device before controlling playback.');

    $this->actingAs($user)->getJson('/api/audio/playback-session')
        ->assertSuccessful()
        ->assertJsonPath('lease_token', $claimed['lease_token'])
        ->assertJsonPath('owner_instance_id', 'pc-tab')
        ->assertJsonPath('position_seconds', 12.4);
});

test('release clears ownership but keeps the last visible track state', function () {
    $user = User::factory()->create();
    $claimed = claimPlaybackSession($user);

    $response = $this->actingAs($user)->postJson('/api/audio/playback-session/release', [
        'instance_id' => 'pc-tab',
        'lease_token' => $claimed['lease_token'],
        'position_seconds' => 21.3,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('lease_token', null);
    $response->assertJsonPath('owner_instance_id', null);
    $response->assertJsonPath('owner_label', null);
    $response->assertJsonPath('state', 'paused');
    $response->assertJsonPath('current_track.id', 41);
    $response->assertJsonPath('position_seconds', 21.3);
});

test('playback sessions are scoped per user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    claimPlaybackSession($owner);

    $this->actingAs($otherUser)->getJson('/api/audio/playback-session')
        ->assertSuccessful()
        ->assertJsonPath('version', 0)
        ->assertJsonPath('owner_instance_id', null)
        ->assertJsonPath('current_track', null);
});
