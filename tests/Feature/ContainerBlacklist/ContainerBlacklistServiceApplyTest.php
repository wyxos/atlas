<?php

use App\Enums\ActionType;
use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\ContainerBlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('skips positively reacted files when applying a container blacklist', function () {
    Queue::fake();
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', null);

    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'action_type' => ActionType::BLACKLIST,
        'blacklisted_at' => now(),
    ]);

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

    $appliedIds = app(ContainerBlacklistService::class)->apply($container->fresh());
    sort($appliedIds);

    expect($appliedIds)->toBe([(int) $dislikedFile->id, (int) $neutralFile->id]);
    expect($likedFile->fresh()->blacklisted_at)->toBeNull();
    expect($lovedFile->fresh()->blacklisted_at)->toBeNull();
    expect($funnyFile->fresh()->blacklisted_at)->toBeNull();
    expect($dislikedFile->fresh()->blacklisted_at)->not->toBeNull();
    expect($neutralFile->fresh()->blacklisted_at)->not->toBeNull();

    Queue::assertPushed(DeleteAutoDislikedFileJob::class, 2);
    Queue::assertPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => $job->filePath === 'downloads/disliked-file.jpg');
    Queue::assertPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => $job->filePath === 'downloads/neutral-file.jpg');
    Queue::assertNotPushed(DeleteAutoDislikedFileJob::class, fn (DeleteAutoDislikedFileJob $job) => in_array($job->filePath, [
        'downloads/liked-file.jpg',
        'downloads/loved-file.jpg',
        'downloads/funny-file.jpg',
    ], true));
});
