<?php

use App\Enums\ActionType;
use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\Tab;
use App\Models\User;
use App\Services\ContainerBlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('skips reacted files when applying a container blacklist', function () {
    Queue::fake();
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', null);

    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'action_type' => ActionType::BLACKLIST,
        'blacklisted_at' => now(),
    ]);

    $actingUser = User::factory()->create();
    $reactionUser = User::factory()->create();

    $likedFile = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/liked-file.jpg',
    ]);
    $lovedFile = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/loved-file.jpg',
    ]);
    $funnyFile = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/funny-file.jpg',
    ]);
    $dislikedFile = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/disliked-file.jpg',
    ]);
    $neutralFile = File::factory()->create([
        'blacklisted_at' => null,
        'path' => 'downloads/neutral-file.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => true,
        'downloaded_at' => now(),
    ]);

    $container->files()->attach([
        $likedFile->id,
        $lovedFile->id,
        $funnyFile->id,
        $dislikedFile->id,
        $neutralFile->id,
    ]);

    Reaction::query()->create([
        'file_id' => $likedFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'like',
    ]);
    Reaction::query()->create([
        'file_id' => $lovedFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'love',
    ]);
    Reaction::query()->create([
        'file_id' => $funnyFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'funny',
    ]);
    Reaction::query()->create([
        'file_id' => $dislikedFile->id,
        'user_id' => $reactionUser->id,
        'type' => 'dislike',
    ]);

    $currentTab = Tab::factory()->for($actingUser)->create();
    $otherUserTab = Tab::factory()->for($actingUser)->create();
    $otherUser = User::factory()->create();
    $foreignTab = Tab::factory()->for($otherUser)->create();
    $currentTab->files()->attach($neutralFile->id, ['position' => 0]);
    $otherUserTab->files()->attach($neutralFile->id, ['position' => 0]);
    $foreignTab->files()->attach($neutralFile->id, ['position' => 0]);

    $appliedIds = app(ContainerBlacklistService::class)->apply($container->fresh(), $actingUser->id);
    sort($appliedIds);

    expect($appliedIds)->toBe([(int) $neutralFile->id]);
    expect($likedFile->fresh()->blacklisted_at)->toBeNull();
    expect($lovedFile->fresh()->blacklisted_at)->toBeNull();
    expect($funnyFile->fresh()->blacklisted_at)->toBeNull();
    expect($dislikedFile->fresh()->blacklisted_at)->toBeNull();
    expect($neutralFile->fresh()->blacklisted_at)->not->toBeNull();
    expect($neutralFile->fresh()->path)->toBeNull();
    expect($neutralFile->fresh()->downloaded)->toBeFalse();
    expect($currentTab->fresh()->files()->where('file_id', $neutralFile->id)->exists())->toBeFalse();
    expect($otherUserTab->fresh()->files()->where('file_id', $neutralFile->id)->exists())->toBeFalse();
    expect($foreignTab->fresh()->files()->where('file_id', $neutralFile->id)->exists())->toBeTrue();

    Queue::assertPushed(DeleteAutoDislikedFileJob::class, 1);
    Queue::assertPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => $job->filePath === 'downloads/neutral-file.jpg');
    Queue::assertNotPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => in_array($job->filePath, [
        'downloads/liked-file.jpg',
        'downloads/loved-file.jpg',
        'downloads/funny-file.jpg',
        'downloads/disliked-file.jpg',
    ], true));
});
