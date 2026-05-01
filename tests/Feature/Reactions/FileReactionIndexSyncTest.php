<?php

use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use App\Services\Local\LocalBrowseIndexSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('syncs browse file and reaction projections when a reaction is added', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    mock(LocalBrowseIndexSyncService::class)
        ->shouldReceive('syncFilesByIds')
        ->once()
        ->with([$file->id])
        ->andReturnNull()
        ->shouldReceive('syncReactionsForFileIds')
        ->once()
        ->with([$file->id])
        ->andReturnNull();

    app(FileReactionService::class)->toggle($file, $user, 'like');
});

it('syncs browse file and reaction projections when a reaction is removed', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_blacklisted' => false,
    ]);

    $this->actingAs($user);

    $service = app(FileReactionService::class);

    mock(LocalBrowseIndexSyncService::class)
        ->shouldReceive('syncFilesByIds')
        ->twice()
        ->with([$file->id])
        ->andReturnNull()
        ->shouldReceive('syncReactionsForFileIds')
        ->twice()
        ->with([$file->id])
        ->andReturnNull();

    $service->toggle($file, $user, 'like');
    $service->toggle($file, $user, 'like');
});
