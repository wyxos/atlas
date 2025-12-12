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

test('does not dispatch download job when user reacts with dislike', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertSuccessful();

    Queue::assertNotPushed(DownloadFile::class);
});

test('does not dispatch download job when removing reaction', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $file = File::factory()->create(['url' => 'https://example.com/test.jpg']);

    // First, create a reaction
    $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    Queue::fake(); // Reset queue fake

    // Then remove it by clicking the same reaction again
    $response = $this->actingAs($admin)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
    ]);

    $response->assertSuccessful();

    // No job should be dispatched when removing a reaction
    Queue::assertNotPushed(DownloadFile::class);
});
