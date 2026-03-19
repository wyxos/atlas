<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
});

it('deletes local assets but keeps the file record by default when requested', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/deletable.jpg',
        'filename' => 'deletable.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/deletable.jpg',
        'preview_path' => 'thumbnails/deletable.jpg',
        'poster_path' => 'posters/deletable.jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'dislike',
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'file');
    Storage::disk(config('downloads.disk'))->put($file->preview_path, 'preview');
    Storage::disk(config('downloads.disk'))->put($file->poster_path, 'poster');

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}", [
        'also_from_disk' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'message' => 'File deleted from disk. Record kept.',
        ]);

    $file->refresh();

    expect($file->downloaded)->toBeFalse()
        ->and($file->downloaded_at)->toBeNull()
        ->and($file->path)->toBeNull()
        ->and($file->preview_path)->toBeNull()
        ->and($file->poster_path)->toBeNull()
        ->and(Reaction::query()->where('file_id', $file->id)->count())->toBe(1);

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/deletable.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('thumbnails/deletable.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('posters/deletable.jpg');
});

it('can delete the local assets and the file record together', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->create();
    $reactionUser = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/remove-record.jpg',
        'filename' => 'remove-record.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/remove-record.jpg',
        'preview_path' => 'thumbnails/remove-record.jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'file');
    Storage::disk(config('downloads.disk'))->put($file->preview_path, 'preview');

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}", [
        'also_from_disk' => true,
        'also_delete_record' => true,
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'message' => 'File deleted from disk and record deleted.',
        ]);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(DownloadTransfer::query()->whereKey($transfer->id)->exists())->toBeFalse();

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/remove-record.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('thumbnails/remove-record.jpg');
});
