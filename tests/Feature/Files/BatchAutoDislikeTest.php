<?php

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use App\Services\TabFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

test('batch auto-dislike sets auto_disliked flag on valid files', function () {
    Bus::fake();

    // Create files that meet auto-dislike conditions
    $file1 = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);
    $file2 = File::factory()->create([
        'source' => 'wallhaven',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 3,
    ]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'auto_disliked_count', 'file_ids']);

    expect($file1->fresh()->auto_disliked)->toBeTrue()
        ->and($file2->fresh()->auto_disliked)->toBeTrue()
        ->and($response->json('auto_disliked_count'))->toBe(2);
});

test('batch auto-dislike creates dislike reactions', function () {
    Bus::fake();

    $file = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertOk();

    $reaction = Reaction::where('file_id', $file->id)
        ->where('user_id', $this->user->id)
        ->first();

    expect($reaction)->not->toBeNull()
        ->and($reaction->type)->toBe('dislike');
});

test('batch auto-dislike de-associates files from user tabs', function () {
    Bus::fake();

    $file = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    $tab = Tab::factory()->for($this->user)->create();
    $tab->files()->attach($file->id, ['position' => 0]);

    expect($tab->files()->where('file_id', $file->id)->exists())->toBeTrue();

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertOk();

    expect($tab->files()->where('file_id', $file->id)->exists())->toBeFalse();
});

test('batch auto-dislike skips files that do not meet conditions', function () {
    Bus::fake();

    // File with reactions - should be skipped
    $fileWithReaction = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);
    Reaction::create([
        'file_id' => $fileWithReaction->id,
        'user_id' => $this->user->id,
        'type' => 'like',
    ]);

    // Local file - should be skipped
    $localFile = File::factory()->create([
        'source' => 'local',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    // Blacklisted file - should be skipped
    $blacklistedFile = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => now(),
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    // Already auto-disliked file - should be skipped
    $alreadyAutoDislikedFile = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => true,
        'previewed_count' => 5,
    ]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [
            $fileWithReaction->id,
            $localFile->id,
            $blacklistedFile->id,
            $alreadyAutoDislikedFile->id,
        ],
    ]);

    $response->assertOk()
        ->assertJson([
            'auto_disliked_count' => 0,
            'file_ids' => [],
        ]);

    expect($fileWithReaction->fresh()->auto_disliked)->toBeFalse()
        ->and($localFile->fresh()->auto_disliked)->toBeFalse()
        ->and($blacklistedFile->fresh()->auto_disliked)->toBeFalse()
        ->and($alreadyAutoDislikedFile->fresh()->auto_disliked)->toBeTrue(); // Still true (wasn't changed)
});

test('batch auto-dislike clears downloaded assets and detaches the auth user tabs', function () {
    Bus::fake();

    $file = File::factory()->create([
        'source' => 'civit-ai',
        'path' => 'downloads/auto-disliked.jpg',
        'preview_path' => 'thumbnails/auto-disliked.jpg',
        'poster_path' => 'posters/auto-disliked.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    $currentTab = Tab::factory()->for($this->user)->create();
    $otherUserTab = Tab::factory()->for($this->user)->create();
    $otherUser = User::factory()->create();
    $foreignTab = Tab::factory()->for($otherUser)->create();

    $currentTab->files()->attach($file->id, ['position' => 0]);
    $otherUserTab->files()->attach($file->id, ['position' => 0]);
    $foreignTab->files()->attach($file->id, ['position' => 0]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertOk()
        ->assertJson([
            'auto_disliked_count' => 1,
            'file_ids' => [$file->id],
        ]);

    expect($file->fresh()->auto_disliked)->toBeTrue()
        ->and($file->fresh()->path)->toBeNull()
        ->and($file->fresh()->preview_path)->toBeNull()
        ->and($file->fresh()->poster_path)->toBeNull()
        ->and($file->fresh()->downloaded)->toBeFalse()
        ->and($currentTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and($otherUserTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeFalse()
        ->and($foreignTab->fresh()->files()->where('file_id', $file->id)->exists())->toBeTrue();

    expect(Reaction::query()
        ->where('file_id', $file->id)
        ->where('user_id', $this->user->id)
        ->value('type'))->toBe('dislike');

    Bus::assertDispatched(DeleteAutoDislikedFileJob::class, function (DeleteAutoDislikedFileJob $job) {
        if (! is_array($job->filePath)) {
            return false;
        }

        $paths = $job->filePath;
        sort($paths);

        return $paths === [
            'downloads/auto-disliked.jpg',
            'posters/auto-disliked.jpg',
            'thumbnails/auto-disliked.jpg',
        ];
    });
});

test('batch auto-dislike skips files with reactions from a different user', function () {
    Bus::fake();

    $otherUser = User::factory()->create();
    $fileWithOtherUserReaction = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    Reaction::create([
        'file_id' => $fileWithOtherUserReaction->id,
        'user_id' => $otherUser->id,
        'type' => 'like',
    ]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$fileWithOtherUserReaction->id],
    ]);

    $response->assertOk()
        ->assertJson([
            'auto_disliked_count' => 0,
            'file_ids' => [],
        ]);

    expect($fileWithOtherUserReaction->fresh()->auto_disliked)->toBeFalse();
});

test('batch auto-dislike is transactional when tab detach fails', function () {
    Bus::fake();

    $file = File::factory()->create([
        'source' => 'civit-ai',
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);
    $tab = Tab::factory()->for($this->user)->create();
    $tab->files()->attach($file->id, ['position' => 0]);

    $mock = \Mockery::mock(TabFileService::class);
    $mock->shouldReceive('detachFilesFromUserTabs')
        ->once()
        ->with($this->user->id, [$file->id])
        ->andThrow(new \RuntimeException('Detach failed'));
    app()->instance(TabFileService::class, $mock);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertStatus(500);

    expect($file->fresh()->auto_disliked)->toBeFalse()
        ->and(Reaction::query()->where('file_id', $file->id)->where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and($tab->files()->where('file_id', $file->id)->exists())->toBeTrue();
});

test('batch auto-dislike requires authentication', function () {
    auth()->logout();

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [1],
    ]);

    $response->assertUnauthorized();
});

test('batch auto-dislike validates file_ids array', function () {
    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => 'not-an-array',
    ]);

    $response->assertUnprocessable();

    $response = $this->postJson('/api/files/auto-dislike/batch', []);

    $response->assertUnprocessable();
});

test('batch auto-dislike handles empty valid file list', function () {
    Bus::fake();

    // Create a file that doesn't meet conditions
    $file = File::factory()->create([
        'source' => 'local', // Local source - won't be auto-disliked
        'path' => null,
        'blacklisted_at' => null,
        'auto_disliked' => false,
        'previewed_count' => 5,
    ]);

    $response = $this->postJson('/api/files/auto-dislike/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertOk()
        ->assertJson([
            'message' => 'No files meet auto-dislike conditions.',
            'auto_disliked_count' => 0,
            'file_ids' => [],
        ]);
});
