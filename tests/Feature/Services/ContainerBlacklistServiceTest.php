<?php

use App\Jobs\DeleteStoredFileJob;
use App\Models\Container;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\ContainerModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ContainerModerationService::class);
});

test('returns empty arrays for empty file collection', function () {
    $result = $this->service->moderate(collect([]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('returns empty arrays when no blacklisted containers exist', function () {
    $file = File::factory()->create();
    Container::factory()->create(['blacklisted_at' => null]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('auto blacklists files from blacklisted containers', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_blacklisted)->toBeTrue();
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    expect(Reaction::query()->where('file_id', $file->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

test('blacklists files for blacklist action type', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/ab/cd/test.jpg',
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();

    expect(Reaction::where('file_id', $file->id)->exists())->toBeFalse();

    Bus::assertDispatched(DeleteStoredFileJob::class, function ($job) use ($file) {
        return $job->filePath === $file->path;
    });
});

test('skips files already auto-blacklisted', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => true,
        'blacklisted_at' => now(),
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files already blacklisted', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => now(),
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files without containers', function () {
    Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
    ]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('does not dispatch delete job when file has no path', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_blacklisted' => false,
        'blacklisted_at' => null,
        'path' => null,
        'preview_path' => null,
        'poster_path' => null,
        'downloaded' => false,
        'downloaded_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->moderate(collect([$file]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    Bus::assertNothingDispatched();
});

test('handles multiple files with different action types', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container3 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);

    $file1 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null]);
    $file1->containers()->attach($container1->id);

    $file2 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null, 'path' => 'downloads/test2.jpg']);
    $file2->containers()->attach($container2->id);

    $file3 = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null, 'path' => 'downloads/test3.jpg']);
    $file3->containers()->attach($container3->id);

    $result = $this->service->moderate(collect([$file1, $file2, $file3]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file1->id);
    expect($result['processedIds'])->toContain($file2->id);
    expect($result['processedIds'])->toContain($file3->id);
    expect($file1->fresh()->auto_blacklisted)->toBeTrue();
    expect($file1->fresh()->blacklisted_at)->not->toBeNull();
    expect($file2->fresh()->blacklisted_at)->not->toBeNull();
    expect($file3->fresh()->blacklisted_at)->not->toBeNull();
});

test('handles files with multiple containers', function () {
    Bus::fake();
    $user = User::factory()->create();
    $this->actingAs($user);

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => null,
    ]);

    $file = File::factory()->create(['auto_blacklisted' => false, 'blacklisted_at' => null]);
    $file->containers()->attach([$container1->id, $container2->id]);

    $result = $this->service->moderate(collect([$file]));

    expect($result['flaggedIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_blacklisted)->toBeTrue();
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
});
