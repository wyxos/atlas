<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake(DownloadFile::class);

    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

test('batch store reactions for multiple files', function () {
    $files = File::factory()->count(3)->create();

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $files[0]->id, 'type' => 'like'],
            ['file_id' => $files[1]->id, 'type' => 'funny'],
            ['file_id' => $files[2]->id, 'type' => 'love'],
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'message',
        'reactions' => [
            '*' => [
                'file_id',
                'reaction' => ['type'],
            ],
        ],
    ]);

    // Verify reactions were created
    expect(Reaction::where('user_id', $this->user->id)->count())->toBe(3);
    expect(Reaction::where('file_id', $files[0]->id)->where('type', 'like')->exists())->toBeTrue();
    expect(Reaction::where('file_id', $files[1]->id)->where('type', 'funny')->exists())->toBeTrue();
    expect(Reaction::where('file_id', $files[2]->id)->where('type', 'love')->exists())->toBeTrue();
});

test('batch store removes existing reactions before creating new ones', function () {
    $file = File::factory()->create();

    // Create existing reaction
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'funny'],
        ],
    ]);

    $response->assertSuccessful();

    // Verify old reaction was removed and new one was created
    $this->assertDatabaseMissing('reactions', [
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);
    $this->assertDatabaseHas('reactions', [
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'funny',
    ]);
});

test('batch store keeps same reaction when same type is sent again', function () {
    $file = File::factory()->create();

    // Create existing reaction
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'like'], // Same type
        ],
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseHas('reactions', [
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);
});

test('batch store detaches same-reaction files from the current user tabs', function () {
    $file = File::factory()->create();
    $currentUserTab = Tab::factory()->for($this->user)->withFiles([$file->id])->create();
    $otherUser = User::factory()->admin()->create();
    $otherUserTab = Tab::factory()->for($otherUser)->withFiles([$file->id])->create();

    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'like'],
        ],
    ]);

    $response->assertSuccessful();

    $this->assertDatabaseMissing('tab_file', [
        'tab_id' => $currentUserTab->id,
        'file_id' => $file->id,
    ]);
    $this->assertDatabaseHas('tab_file', [
        'tab_id' => $otherUserTab->id,
        'file_id' => $file->id,
    ]);
    $this->assertDatabaseHas('reactions', [
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);
});

test('batch store clears blacklist when the same positive reaction is re-applied', function () {
    $file = File::factory()->create([
        'blacklisted_at' => now()->subMinute(),
    ]);

    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $this->user->id,
        'type' => 'love',
    ]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'love'],
        ],
    ]);

    $response->assertSuccessful();

    $file->refresh();

    expect($file->blacklisted_at)->toBeNull();
});

test('batch store removes auto_blacklisted flag for positive reactions', function () {
    Bus::fake();

    $file = File::factory()->create(['auto_blacklisted' => true]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'like'],
        ],
    ]);

    $response->assertSuccessful();

    $file->refresh();
    $this->assertFalse($file->auto_blacklisted);
});

test('batch store validates required fields', function () {
    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => 999, 'type' => 'invalid'],
        ],
    ]);

    $response->assertUnprocessable();
});

test('batch store requires authentication', function () {
    auth()->logout();

    $file = File::factory()->create();

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'like'],
        ],
    ]);

    $response->assertUnauthorized();
});
