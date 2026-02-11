<?php

use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;

uses(RefreshDatabase::class);

it('queues a scout reindex when a reaction is toggled on', function () {
    Queue::fake();
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', ['queue' => 'scout']);

    $user = User::factory()->create();
    $file = File::factory()->create();

    app(FileReactionService::class)->toggle($file, $user, 'dislike');

    Queue::assertPushed(MakeSearchable::class, function (MakeSearchable $job) use ($file) {
        return (int) ($job->models->first()?->id ?? 0) === (int) $file->id;
    });
});

it('queues a scout reindex when a reaction is toggled off', function () {
    Queue::fake();
    Config::set('scout.driver', 'collection');
    Config::set('scout.queue', ['queue' => 'scout']);

    $user = User::factory()->create();
    $file = File::factory()->create();

    $svc = app(FileReactionService::class);

    // First toggle adds dislike.
    $svc->toggle($file, $user, 'dislike');
    // Second toggle removes dislike.
    $svc->toggle($file, $user, 'dislike');

    Queue::assertPushed(MakeSearchable::class, function (MakeSearchable $job) use ($file) {
        return (int) ($job->models->first()?->id ?? 0) === (int) $file->id;
    });
});

