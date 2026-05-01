<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('dispatches download job when user reacts with like', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();

    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });
});

test('dispatches download job when user reacts with love', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();

    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });
});

test('dispatches download job when user reacts with funny', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'funny',
    ]);

    $response->assertSuccessful();

    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });
});

test('rejects removed dislike reaction type without dispatching download job', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertUnprocessable();

    Queue::assertNotPushed(DownloadFile::class);
});

test('dispatches download job when reapplying the same positive reaction', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    // First, create a reaction
    $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    // Clear any jobs from the first request
    Queue::fake();

    // Re-apply the same reaction again
    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('message', 'Reaction updated.');

    expect($file->fresh()->reactions()->where('user_id', $admin->id)->count())->toBe(1);
    expect($file->fresh()->reactions()->where('user_id', $admin->id)->value('type'))->toBe('like');

    Queue::assertPushed(DownloadFile::class, function ($job) use ($file) {
        return $job->fileId === $file->id;
    });
});
