<?php

use App\Jobs\DownloadFile;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeDownloadedRemoteFile(array $overrides = []): File
{
    static $sequence = 0;

    $sequence++;

    return File::factory()->create([
        'source' => 'CivitAI',
        'url' => "https://image.civitai.com/example-{$sequence}/original=true/example-{$sequence}.jpeg",
        'referrer_url' => "https://civitai.com/images/{$sequence}",
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/existing.jpg',
        'preview_path' => 'downloads/existing-preview.jpg',
        'poster_path' => null,
        'not_found' => false,
        ...$overrides,
    ]);
}

test('downloaded non-local files can be queued for re-download after the source still exists', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = makeDownloadedRemoteFile();

    Storage::disk(config('downloads.disk'))->put('downloads/existing.jpg', 'original file');
    Storage::disk(config('downloads.disk'))->put('downloads/existing-preview.jpg', 'preview file');

    Http::fake([
        $file->referrer_url => Http::response('', 200),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/redownload");

    $response->assertSuccessful()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('not_found', false)
        ->assertJsonPath('file.downloaded', false)
        ->assertJsonPath('file.path', null)
        ->assertJsonPath('file.not_found', false);

    $file->refresh();

    expect($file->downloaded)->toBeFalse()
        ->and($file->path)->toBeNull()
        ->and($file->preview_path)->toBeNull()
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing.jpg'))->toBeFalse()
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing-preview.jpg'))->toBeFalse();

    Queue::assertPushed(DownloadFile::class, fn (DownloadFile $job): bool => $job->fileId === $file->id);
});

test('re-download marks a downloaded file as 404 without deleting the current disk file when the source is gone', function () {
    Queue::fake();
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = makeDownloadedRemoteFile();

    Storage::disk(config('downloads.disk'))->put('downloads/existing.jpg', 'original file');
    Storage::disk(config('downloads.disk'))->put('downloads/existing-preview.jpg', 'preview file');

    Http::fake([
        $file->referrer_url => Http::response('', 404),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/redownload");

    $response->assertSuccessful()
        ->assertJsonPath('queued', false)
        ->assertJsonPath('not_found', true)
        ->assertJsonPath('file.downloaded', true)
        ->assertJsonPath('file.path', 'downloads/existing.jpg')
        ->assertJsonPath('file.not_found', true);

    $file->refresh();

    expect($file->not_found)->toBeTrue()
        ->and($file->downloaded)->toBeTrue()
        ->and($file->path)->toBe('downloads/existing.jpg')
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing.jpg'))->toBeTrue()
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing-preview.jpg'))->toBeTrue();

    Queue::assertNotPushed(DownloadFile::class);
});

test('re-download is not available for local sources or already 404 flagged files', function () {
    Queue::fake();

    $user = User::factory()->admin()->create();
    $localFile = makeDownloadedRemoteFile([
        'source' => 'local',
        'not_found' => false,
    ]);
    $missingRemoteFile = makeDownloadedRemoteFile([
        'source' => 'CivitAI',
        'not_found' => true,
    ]);

    $this->actingAs($user)
        ->postJson("/api/files/{$localFile->id}/redownload")
        ->assertUnprocessable();

    $this->actingAs($user)
        ->postJson("/api/files/{$missingRemoteFile->id}/redownload")
        ->assertUnprocessable();

    Queue::assertNotPushed(DownloadFile::class);
});

test('404 flagged downloaded files can be marked corrupted to delete disk assets reactions and the file row', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $file = makeDownloadedRemoteFile([
        'not_found' => true,
    ]);
    $container = Container::factory()->create();
    $tab = Tab::factory()->for($user)->withFiles([$file->id])->create();

    Storage::disk(config('downloads.disk'))->put('downloads/existing.jpg', 'original file');
    Storage::disk(config('downloads.disk'))->put('downloads/existing-preview.jpg', 'preview file');

    $container->files()->attach($file->id);
    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($user)->deleteJson("/api/files/{$file->id}/corrupted");

    $response->assertSuccessful()
        ->assertJsonPath('deleted', true)
        ->assertJsonPath('not_found', true);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(DB::table('container_file')->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(DB::table('tab_file')->where('file_id', $file->id)->exists())->toBeFalse()
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing.jpg'))->toBeFalse()
        ->and(Storage::disk(config('downloads.disk'))->exists('downloads/existing-preview.jpg'))->toBeFalse();

    $tab->refresh();
    expect($tab->files()->count())->toBe(0);
});

test('marking corrupted is only available for 404 flagged non-local files', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->admin()->create();
    $notMissing = makeDownloadedRemoteFile([
        'not_found' => false,
    ]);
    $localMissing = makeDownloadedRemoteFile([
        'source' => 'local',
        'not_found' => true,
    ]);

    Storage::disk(config('downloads.disk'))->put('downloads/existing.jpg', 'original file');

    $this->actingAs($user)
        ->deleteJson("/api/files/{$notMissing->id}/corrupted")
        ->assertUnprocessable();

    $this->actingAs($user)
        ->deleteJson("/api/files/{$localMissing->id}/corrupted")
        ->assertUnprocessable();

    expect(File::query()->whereKey($notMissing->id)->exists())->toBeTrue()
        ->and(File::query()->whereKey($localMissing->id)->exists())->toBeTrue();
});
