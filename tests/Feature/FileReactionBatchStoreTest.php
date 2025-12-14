<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

test('batch store reactions for multiple files', function () {
    $files = File::factory()->count(3)->create();

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $files[0]->id, 'type' => 'like'],
            ['file_id' => $files[1]->id, 'type' => 'dislike'],
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
    expect(Reaction::where('file_id', $files[1]->id)->where('type', 'dislike')->exists())->toBeTrue();
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
            ['file_id' => $file->id, 'type' => 'dislike'],
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
        'type' => 'dislike',
    ]);
});

test('batch store toggles reaction off if same type is sent again', function () {
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

    // Verify reaction was removed (toggled off)
    $this->assertDatabaseMissing('reactions', [
        'file_id' => $file->id,
        'user_id' => $this->user->id,
    ]);
});

test('batch store removes auto_disliked flag for positive reactions', function () {
    $file = File::factory()->create(['auto_disliked' => true]);

    $response = $this->postJson('/api/files/reactions/batch/store', [
        'reactions' => [
            ['file_id' => $file->id, 'type' => 'like'],
        ],
    ]);

    $response->assertSuccessful();

    $file->refresh();
    $this->assertFalse($file->auto_disliked);
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

