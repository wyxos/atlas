<?php

use App\Models\File;
use App\Models\Queue;
use App\Models\User;

it('can create a queue', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    expect($queue)->toBeInstanceOf(Queue::class);
    expect($queue->user_id)->toBe($user->id);
    expect($queue->position)->toBe(1);
    expect($queue->active_file_id)->toBeNull();
});

it('can create a queue with active file', function () {
    $user = User::factory()->create();
    $file = File::create([
        'source' => 'test',
        'filename' => 'song.mp3',
        'path' => '/path/to/song.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'test-hash',
    ]);

    $queue = Queue::create([
        'user_id' => $user->id,
        'active_file_id' => $file->id,
        'position' => 1,
    ]);

    expect($queue)->toBeInstanceOf(Queue::class);
    expect($queue->user_id)->toBe($user->id);
    expect($queue->active_file_id)->toBe($file->id);
    expect($queue->position)->toBe(1);
});

it('casts fields to correct types', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => (string) $user->id,
        'position' => '5',
    ]);

    expect($queue->user_id)->toBeInt();
    expect($queue->position)->toBeInt();
    expect($queue->user_id)->toBe($user->id);
    expect($queue->position)->toBe(5);
});

it('belongs to a user', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    expect($queue->user)->toBeInstanceOf(User::class);
    expect($queue->user->id)->toBe($user->id);
    expect($queue->user->name)->toBe($user->name);
});

it('belongs to an active file', function () {
    $user = User::factory()->create();
    $file = File::create([
        'source' => 'test',
        'filename' => 'active-song.mp3',
        'path' => '/path/to/active-song.mp3',
        'size' => 2048,
        'mime_type' => 'audio/mpeg',
        'hash' => 'active-hash',
    ]);

    $queue = Queue::create([
        'user_id' => $user->id,
        'active_file_id' => $file->id,
        'position' => 1,
    ]);

    expect($queue->activeFile)->toBeInstanceOf(File::class);
    expect($queue->activeFile->id)->toBe($file->id);
    expect($queue->activeFile->filename)->toBe('active-song.mp3');
});

it('can have null active file', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    expect($queue->activeFile)->toBeNull();
});

it('can have many files', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    $file1 = File::create([
        'source' => 'test',
        'filename' => 'song1.mp3',
        'path' => '/path/to/song1.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash1',
    ]);

    $file2 = File::create([
        'source' => 'test',
        'filename' => 'song2.mp3',
        'path' => '/path/to/song2.mp3',
        'size' => 2048,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash2',
    ]);

    $queue->files()->attach([
        $file1->id => ['position' => 1],
        $file2->id => ['position' => 2]
    ]);

    expect($queue->files)->toHaveCount(2);
    expect($queue->files->first())->toBeInstanceOf(File::class);
    expect($queue->files->pluck('filename')->toArray())->toContain('song1.mp3', 'song2.mp3');
});

it('orders files by position in pivot', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    $file1 = File::create([
        'source' => 'test',
        'filename' => 'first-song.mp3',
        'path' => '/path/to/first-song.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash1',
    ]);

    $file2 = File::create([
        'source' => 'test',
        'filename' => 'second-song.mp3',
        'path' => '/path/to/second-song.mp3',
        'size' => 2048,
        'mime_type' => 'audio/mpeg',
        'hash' => 'hash2',
    ]);

    // Attach in reverse order to test ordering
    $queue->files()->attach([
        $file1->id => ['position' => 2],
        $file2->id => ['position' => 1]
    ]);

    $orderedFiles = $queue->files()->get();
    expect($orderedFiles->first()->filename)->toBe('second-song.mp3');
    expect($orderedFiles->last()->filename)->toBe('first-song.mp3');
});

it('file can belong to many queues', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $queue1 = Queue::create([
        'user_id' => $user1->id,
        'position' => 1,
    ]);

    $queue2 = Queue::create([
        'user_id' => $user2->id,
        'position' => 1,
    ]);

    $file = File::create([
        'source' => 'test',
        'filename' => 'shared-song.mp3',
        'path' => '/path/to/shared-song.mp3',
        'size' => 3072,
        'mime_type' => 'audio/mpeg',
        'hash' => 'shared-hash',
    ]);

    $file->queues()->attach([
        $queue1->id => ['position' => 1],
        $queue2->id => ['position' => 1]
    ]);

    expect($file->queues)->toHaveCount(2);
    expect($file->queues->first())->toBeInstanceOf(Queue::class);
    expect($file->queues->pluck('user_id')->toArray())->toContain($user1->id, $user2->id);
});

it('can detach files from queue', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    $file = File::create([
        'source' => 'test',
        'filename' => 'test-song.mp3',
        'path' => '/path/to/test-song.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'test-hash',
    ]);

    $queue->files()->attach($file->id, ['position' => 1]);
    expect($queue->files)->toHaveCount(1);

    $queue->files()->detach($file->id);
    $queue->refresh();
    expect($queue->files)->toHaveCount(0);
});

it('user can have many queues', function () {
    $user = User::factory()->create();

    $queue1 = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    $queue2 = Queue::create([
        'user_id' => $user->id,
        'position' => 2,
    ]);

    expect($user->queues)->toHaveCount(2);
    expect($user->queues->first())->toBeInstanceOf(Queue::class);
    expect($user->queues->pluck('position')->toArray())->toContain(1, 2);
});

it('deleting user cascades to queues', function () {
    $user = User::factory()->create();

    $queue = Queue::create([
        'user_id' => $user->id,
        'position' => 1,
    ]);

    expect(Queue::count())->toBe(1);

    $user->delete();

    expect(Queue::count())->toBe(0);
});

it('deleting active file sets active_file_id to null', function () {
    $user = User::factory()->create();
    $file = File::create([
        'source' => 'test',
        'filename' => 'to-be-deleted.mp3',
        'path' => '/path/to/to-be-deleted.mp3',
        'size' => 1024,
        'mime_type' => 'audio/mpeg',
        'hash' => 'delete-hash',
    ]);

    $queue = Queue::create([
        'user_id' => $user->id,
        'active_file_id' => $file->id,
        'position' => 1,
    ]);

    expect($queue->active_file_id)->toBe($file->id);

    $file->delete();
    $queue->refresh();

    expect($queue->active_file_id)->toBeNull();
});
