<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('reaction store can prompt to force re-download when file is already downloaded and already reacted', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/test.jpg',
    ]);

    Storage::disk(config('downloads.disk'))->put('downloads/test.jpg', 'ok');

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'love',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'love');
    $response->assertJsonPath('should_prompt_redownload', true);

    Queue::assertPushed(DownloadFile::class, fn (DownloadFile $job) => $job->fileId === $file->id);
});

test('reaction store force_download resets downloaded file and does not toggle off same reaction', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/test.jpg',
        'preview_path' => 'downloads/preview.jpg',
        'poster_path' => 'downloads/poster.jpg',
    ]);

    Storage::disk(config('downloads.disk'))->put('downloads/test.jpg', 'ok');
    Storage::disk(config('downloads.disk'))->put('downloads/preview.jpg', 'ok');
    Storage::disk(config('downloads.disk'))->put('downloads/poster.jpg', 'ok');

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'like',
        'force_download' => true,
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('should_prompt_redownload', false);

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->poster_path)->toBeNull();

    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->value('type'))->toBe('like');

    Queue::assertPushed(DownloadFile::class, fn (DownloadFile $job) => $job->fileId === $file->id);
});
