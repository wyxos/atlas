<?php

use App\Models\File;
use App\Models\User;
use App\Services\FileReactionService;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows a newly reacted file in local reacted browse immediately', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $this->actingAs($user);

    app(FileReactionService::class)->toggle($file, $user, 'like');

    $result = app(LocalService::class)->fetch([
        'reaction_mode' => 'reacted',
    ]);

    expect(collect($result['files'])->pluck('id')->all())->toBe([$file->id]);
});

it('removes a toggled off reaction from local reacted browse immediately', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $this->actingAs($user);

    $service = app(FileReactionService::class);
    $service->toggle($file, $user, 'like');
    $service->toggle($file, $user, 'like');

    $result = app(LocalService::class)->fetch([
        'reaction_mode' => 'reacted',
    ]);

    expect($result['files'])->toBe([]);
});
