<?php

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('batch blacklists loaded files and detaches them from the current users tabs', function () {
    $admin = User::factory()->admin()->create();
    $file1 = File::factory()->create(['blacklisted_at' => null]);
    $file2 = File::factory()->create(['blacklisted_at' => null]);
    $tab = Tab::factory()
        ->for($admin)
        ->withFiles([$file1->id, $file2->id])
        ->create();

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file1->id, $file2->id],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure([
            'message',
            'results' => [
                '*' => ['id', 'blacklisted_at'],
            ],
        ])
        ->assertJsonMissingPath('results.0.blacklist_reason');

    $file1->refresh();
    $file2->refresh();
    $tab->refresh();

    expect($file1->blacklisted_at)->not->toBeNull()
        ->and($file2->blacklisted_at)->not->toBeNull()
        ->and($tab->files()->count())->toBe(0);
});

test('batch blacklist normalizes files that are already blacklisted', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $alreadyBlacklisted = File::factory()->create([
        'blacklisted_at' => now()->subHour(),
        'path' => 'downloads/already-blacklisted.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$alreadyBlacklisted->id],
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'results');

    $alreadyBlacklisted->refresh();

    expect($alreadyBlacklisted->blacklisted_at)->not->toBeNull()
        ->and($alreadyBlacklisted->path)->toBeNull()
        ->and($alreadyBlacklisted->downloaded)->toBeFalse()
        ->and($alreadyBlacklisted->downloaded_at)->toBeNull();

    Queue::assertPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => $job->filePath === 'downloads/already-blacklisted.jpg');
});

test('batch blacklist clears auto-disliked marker and removes existing reactions because blacklist is plain blacklist', function () {
    Queue::fake();

    $admin = User::factory()->admin()->create();
    $otherUser = User::factory()->create();
    $file = File::factory()->create([
        'auto_disliked' => true,
        'blacklisted_at' => null,
        'path' => 'downloads/reacted-file.jpg',
        'preview_path' => 'thumbnails/reacted-file.jpg',
        'poster_path' => 'posters/reacted-file.jpg',
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $admin->id,
        'type' => 'dislike',
    ]);
    Reaction::create([
        'file_id' => $file->id,
        'user_id' => $otherUser->id,
        'type' => 'love',
    ]);

    $response = $this->actingAs($admin)->postJson('/api/files/blacklist/batch', [
        'file_ids' => [$file->id],
    ]);

    $response->assertSuccessful();

    $file->refresh();

    expect($file->blacklisted_at)->not->toBeNull()
        ->and($file->auto_disliked)->toBeFalse()
        ->and($file->path)->toBeNull()
        ->and($file->preview_path)->toBeNull()
        ->and($file->poster_path)->toBeNull()
        ->and($file->downloaded)->toBeFalse()
        ->and($file->downloaded_at)->toBeNull()
        ->and(Reaction::where('file_id', $file->id)->count())->toBe(0);

    Queue::assertPushed(DeleteAutoDislikedFileJob::class, function (DeleteAutoDislikedFileJob $job): bool {
        if (! is_array($job->filePath)) {
            return false;
        }

        $paths = $job->filePath;
        sort($paths);

        return $paths === [
            'downloads/reacted-file.jpg',
            'posters/reacted-file.jpg',
            'thumbnails/reacted-file.jpg',
        ];
    });
});
