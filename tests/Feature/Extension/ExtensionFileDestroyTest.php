<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
});

function setExtensionFileDestroyApiKey(string $value, ?int $userId = null): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($userId !== null) {
        DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_user_id',
            'machine' => '',
        ], [
            'value' => (string) $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('lets the extension delete a downloaded file from disk and the database', function () {
    Storage::fake(config('downloads.disk'));

    $user = User::factory()->create();
    setExtensionFileDestroyApiKey('valid-api-key', $user->id);
    $file = File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/extension-delete.jpg',
        'preview_path' => 'downloads/preview/extension-delete.jpg',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Storage::disk(config('downloads.disk'))->put($file->path, 'file');
    Storage::disk(config('downloads.disk'))->put($file->preview_path, 'preview');

    $response = $this
        ->withHeader('X-Atlas-Api-Key', 'valid-api-key')
        ->deleteJson("/api/extension/files/{$file->id}", [
            'also_from_disk' => true,
            'also_delete_record' => true,
        ]);

    $response->assertSuccessful()
        ->assertJson([
            'deleted' => true,
            'file_id' => $file->id,
            'message' => 'File deleted from disk and record deleted.',
        ]);

    expect(File::query()->whereKey($file->id)->exists())->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();

    Storage::disk(config('downloads.disk'))->assertMissing('downloads/extension-delete.jpg');
    Storage::disk(config('downloads.disk'))->assertMissing('downloads/preview/extension-delete.jpg');
});

it('requires extension authentication to delete a file', function () {
    $file = File::factory()->create();

    $response = $this->deleteJson("/api/extension/files/{$file->id}", [
        'also_from_disk' => true,
        'also_delete_record' => true,
    ]);

    $response->assertUnauthorized();
});
