<?php

use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function setExtensionReactionApiKey(string $value): void
{
    DB::table('settings')->insert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('extension reactions endpoint requires a valid api key', function () {
    setExtensionReactionApiKey('valid-api-key');

    $response = $this->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/new-file.jpg',
    ]);

    $response->assertUnauthorized();
});

test('extension reactions endpoint creates file applies reaction and queues download', function () {
    Queue::fake();

    $user = User::factory()->create();
    config(['downloads.extension_user_id' => $user->id]);
    setExtensionReactionApiKey('valid-api-key');

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/reactions', [
        'type' => 'like',
        'url' => 'https://cdn.example.test/media/new-file.jpg',
        'referrer_url' => 'https://www.example.test/post/123',
        'referrer_url_hash_aware' => 'https://www.example.test/post/123#media-id-42',
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('reaction.type', 'like');
    $response->assertJsonPath('download.requested', true);
    $response->assertJsonPath('download.downloaded_at', null);
    $response->assertJsonPath('file.url', 'https://cdn.example.test/media/new-file.jpg');
    $response->assertJsonPath('file.referrer_url', 'https://www.example.test/post/123#media-id-42');
    $response->assertJsonPath('file.preview_url', 'https://cdn.example.test/media/new-file.jpg');

    $file = File::query()->where('url', 'https://cdn.example.test/media/new-file.jpg')->first();
    expect($file)->not->toBeNull();
    expect($file?->source)->toBe('extension');
    expect($file?->referrer_url)->toBe('https://www.example.test/post/123#media-id-42');
    expect($file?->preview_url)->toBe('https://cdn.example.test/media/new-file.jpg');

    $reaction = Reaction::query()
        ->where('user_id', $user->id)
        ->where('file_id', $file?->id)
        ->first();

    expect($reaction)->not->toBeNull();
    expect($reaction?->type)->toBe('like');

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file): bool {
        return $job->fileId === $file?->id;
    });
});
