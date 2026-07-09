<?php

use App\Models\Album;
use App\Models\AlbumCover;
use App\Models\Container;
use App\Models\File;
use App\Models\MediaProcessorTask;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('admin can view file details', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'file' => [
            'id',
        ],
    ]);
});

test('admin receives FileResource with correct structure', function () {
    $admin = User::factory()->admin()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($admin)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['file'])->toBeArray();
    expect($data['file']['id'])->toBe($file->id);
});

test('regular user can view file details', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
});

test('guest cannot view file details', function () {
    $file = File::factory()->create();

    $response = $this->getJson("/api/files/{$file->id}");

    $response->assertUnauthorized();
});

test('viewing non-existent file returns 404', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->getJson('/api/files/99999');

    $response->assertNotFound();
});

test('file show includes container state and stats for the current user', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();
    $container = Container::factory()->create([
        'type' => 'gallery',
        'source' => 'CivitAI',
        'source_id' => 'gallery-123',
        'referrer' => 'https://example.com/gallery/123',
        'action_type' => 'blacklist',
        'blacklisted_at' => now(),
    ]);

    $unreactedFile = File::factory()->create();
    $blacklistedFile = File::factory()->create([
        'blacklisted_at' => now(),
    ]);
    $notFoundFile = File::factory()->create([
        'not_found' => true,
    ]);
    $funnyFile = File::factory()->create();
    $positiveFile = File::factory()->create();

    $container->files()->attach([
        $file->id,
        $unreactedFile->id,
        $blacklistedFile->id,
        $notFoundFile->id,
        $funnyFile->id,
        $positiveFile->id,
    ]);

    Reaction::create([
        'file_id' => $funnyFile->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Reaction::create([
        'file_id' => $positiveFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.containers.0.id', $container->id);
    $response->assertJsonPath('file.containers.0.type', 'gallery');
    $response->assertJsonPath('file.containers.0.source', 'CivitAI');
    $response->assertJsonPath('file.containers.0.source_id', 'gallery-123');
    $response->assertJsonPath('file.containers.0.referrer', 'https://example.com/gallery/123');
    $response->assertJsonPath('file.containers.0.blacklisted', true);
    $response->assertJsonPath('file.containers.0.action_type', 'blacklist');
    $response->assertJsonPath('file.containers.0.file_stats.unreacted', 2);
    $response->assertJsonPath('file.containers.0.file_stats.blacklisted', 1);
    $response->assertJsonPath('file.containers.0.file_stats.positive', 2);
});

test('file show includes active prompt moderation match details', function () {
    $user = User::factory()->create();
    $file = File::factory()->create();
    $file->metadata()->create([
        'payload' => [
            'prompt' => 'a red car beside a blue boat',
        ],
    ]);
    $rule = ModerationRule::factory()->create([
        'name' => 'Vehicle rule',
        'active' => true,
        'action_type' => 'blacklist',
        'op' => 'any',
        'terms' => ['car', 'train'],
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.prompt_moderation_rule.id', $rule->id);
    $response->assertJsonPath('file.prompt_moderation_rule.name', 'Vehicle rule');
    $response->assertJsonPath('file.prompt_moderation_rule.matched_terms', ['car']);
    $response->assertJsonPath('file.prompt_moderation_rule.reason', 'Matched prompt terms: car');
});

test('file show includes concerned containers for container auto blacklist state', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'blacklisted_at' => now(),
        'auto_blacklisted' => true,
    ]);
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist-name',
        'referrer' => 'https://www.deviantart.com/artist-name',
        'action_type' => 'blacklist',
        'blacklisted_at' => now(),
    ]);

    $container->files()->attach($file->id);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.auto_blacklist_containers.0.id', $container->id);
    $response->assertJsonPath('file.auto_blacklist_containers.0.source_id', 'artist-name');
    $response->assertJsonPath('file.auto_blacklist_containers.0.blacklisted', true);
});

test('file show includes audio album cover url', function () {
    $user = User::factory()->create();
    $audio = File::factory()->create([
        'mime_type' => 'audio/mpeg',
        'ext' => 'mp3',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/test-track.mp3',
        'preview_url' => null,
        'preview_path' => null,
        'poster_path' => null,
    ]);
    $album = Album::factory()->create();
    $audio->albums()->attach($album->id);
    $cover = AlbumCover::factory()->create([
        'album_id' => $album->id,
        'file_id' => $audio->id,
        'path' => 'imports/aa/bb/covers/test-cover.jpg',
        'path_hash' => hash('sha256', 'imports/aa/bb/covers/test-cover.jpg'),
        'is_default' => true,
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$audio->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.cover_url', "/api/audio/album-covers/{$cover->id}");
});

test('downloaded files with failed preview generation do not expose remote preview fallbacks', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'facebook.com',
        'url' => 'https://www.facebook.com/reel/123',
        'preview_url' => 'https://www.facebook.com/reel/123',
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/video.mp4',
        'preview_path' => null,
        'poster_path' => null,
    ]);
    MediaProcessorTask::query()->create([
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'operation' => 'video_preview',
        'status' => 'failed',
        'phase' => 'failed',
        'progress' => 100,
        'storage_profile' => 'atlas-local',
        'input_path' => $file->path,
        'output_paths' => [
            'preview_path' => 'downloads/aa/bb/preview/video.mp4',
            'poster_path' => 'downloads/aa/bb/preview/video.jpg',
        ],
        'failed_at' => now(),
        'error_message' => 'Processor exited with code 1.',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.preview_url', null);
    $response->assertJsonPath('file.preview_file_url', null);
    $response->assertJsonPath('file.poster_url', null);
    $response->assertJsonPath('file.preview_generation.status', 'failed');
    $response->assertJsonPath('file.preview_generation.can_retry', true);
    $response->assertJsonPath('file.preview_generation.message', 'Processor exited with code 1.');
});

test('downloaded files with unavailable preview generation do not expose remote preview fallbacks', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'facebook.com',
        'url' => 'https://www.facebook.com/reel/123',
        'preview_url' => 'https://www.facebook.com/reel/123',
        'mime_type' => 'video/mp4',
        'downloaded' => true,
        'downloaded_at' => now(),
        'path' => 'downloads/aa/bb/video.mp4',
        'preview_path' => null,
        'poster_path' => null,
    ]);
    MediaProcessorTask::query()->create([
        'id' => (string) Str::uuid(),
        'file_id' => $file->id,
        'operation' => 'video_preview',
        'status' => 'failed',
        'phase' => 'unavailable',
        'progress' => 100,
        'storage_profile' => 'atlas-local',
        'input_path' => $file->path,
        'output_paths' => [],
        'failed_at' => now(),
        'last_event_at' => now(),
        'error_code' => 'preview_redownload_not_found',
        'error_message' => 'Original file is missing and the remote source is no longer available.',
    ]);

    $response = $this->actingAs($user)->getJson("/api/files/{$file->id}");

    $response->assertSuccessful();
    $response->assertJsonPath('file.preview_url', null);
    $response->assertJsonPath('file.preview_file_url', null);
    $response->assertJsonPath('file.poster_url', null);
    $response->assertJsonPath('file.preview_generation.status', 'unavailable');
    $response->assertJsonPath('file.preview_generation.can_retry', false);
    $response->assertJsonPath('file.preview_generation.message', 'Original file is missing and the remote source is no longer available.');
});
