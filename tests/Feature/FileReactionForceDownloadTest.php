<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\FileReactionService;
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

test('reaction store skips the redownload prompt when the downloaded file is missing locally', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/missing.jpg',
    ]);

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
    $response->assertJsonPath('should_prompt_redownload', false);

    Queue::assertPushed(DownloadFile::class, fn (DownloadFile $job) => $job->fileId === $file->id);
});

test('reaction store deletes downloaded assets when a file is disliked', function () {
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

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'dislike');
    $response->assertJsonPath('should_prompt_redownload', false);

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->poster_path)->toBeNull();
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->value('type'))->toBe('dislike');

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/test.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/poster.jpg');
    Queue::assertNotPushed(DownloadFile::class);
});

test('reaction store keeps the dislike and clears downloaded assets when a disliked file is disliked again', function () {
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
        'type' => 'dislike',
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/reaction", [
        'type' => 'dislike',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'dislike');
    $response->assertJsonPath('should_prompt_redownload', false);

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect($file->poster_path)->toBeNull();
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->count())->toBe(1);
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->value('type'))->toBe('dislike');

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/test.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/poster.jpg');
    Queue::assertNotPushed(DownloadFile::class);
});

test('reaction service set keeps the same dislike while clearing downloaded assets', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/test.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/test.jpg',
        'preview_path' => 'downloads/preview.jpg',
    ]);

    Storage::disk(config('downloads.disk'))->put('downloads/test.jpg', 'ok');
    Storage::disk(config('downloads.disk'))->put('downloads/preview.jpg', 'ok');

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    $result = app(FileReactionService::class)->set($file, $user, 'dislike', [
        'deferHeavySideEffects' => true,
        'queueDownload' => false,
    ]);

    expect($result['reaction'])->toBe(['type' => 'dislike']);
    expect($result['changed'])->toBeFalse();

    $file->refresh();
    expect($file->downloaded)->toBeFalse();
    expect($file->path)->toBeNull();
    expect($file->preview_path)->toBeNull();
    expect(Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file->id)
        ->count())->toBe(1);

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/test.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview.jpg');
    Queue::assertNotPushed(DownloadFile::class);
});
