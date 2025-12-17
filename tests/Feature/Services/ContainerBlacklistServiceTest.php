<?php

use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\Container;
use App\Models\File;
use App\Services\ContainerBlacklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ContainerBlacklistService;
});

test('returns empty arrays for empty file collection', function () {
    $result = $this->service->filterBannedContainers(collect([]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('returns empty arrays when no blacklisted containers exist', function () {
    $file = File::factory()->create();
    Container::factory()->create(['blacklisted_at' => null]);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('flags files for ui_countdown action type', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toContain($file->id);
    expect($result['processedIds'])->toBeEmpty();
    expect($file->fresh()->auto_disliked)->toBeFalse();
    expect($file->fresh()->blacklisted_at)->toBeNull();
    Bus::assertNothingDispatched();
});

test('auto-dislikes files for auto_dislike action type', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'auto_dislike',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/ab/cd/test.jpg',
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_disliked)->toBeTrue();
    Bus::assertDispatched(DeleteAutoDislikedFileJob::class, function ($job) use ($file) {
        return $job->filePath === $file->path;
    });
});

test('blacklists files for blacklist action type', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'path' => 'downloads/ab/cd/test.jpg',
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->blacklisted_at)->not->toBeNull();
    Bus::assertDispatched(DeleteAutoDislikedFileJob::class, function ($job) use ($file) {
        return $job->filePath === $file->path;
    });
});

test('skips files already auto-disliked', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => true,
        'blacklisted_at' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files already blacklisted', function () {
    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => now(),
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('skips files without containers', function () {
    Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
    ]);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['flaggedFileIds'])->toBeEmpty();
    expect($result['processedIds'])->toBeEmpty();
});

test('does not dispatch delete job when file has no path', function () {
    Bus::fake();

    $container = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'auto_dislike',
    ]);
    $file = File::factory()->create([
        'auto_disliked' => false,
        'blacklisted_at' => null,
        'path' => null,
    ]);
    $file->containers()->attach($container->id);

    $result = $this->service->filterBannedContainers(collect([$file]));

    expect($result['processedIds'])->toContain($file->id);
    expect($file->fresh()->auto_disliked)->toBeTrue();
    Bus::assertNothingDispatched();
});

test('handles multiple files with different action types', function () {
    Bus::fake();

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'auto_dislike',
    ]);
    $container3 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'blacklist',
    ]);

    $file1 = File::factory()->create(['auto_disliked' => false, 'blacklisted_at' => null]);
    $file1->containers()->attach($container1->id);

    $file2 = File::factory()->create(['auto_disliked' => false, 'blacklisted_at' => null, 'path' => 'downloads/test2.jpg']);
    $file2->containers()->attach($container2->id);

    $file3 = File::factory()->create(['auto_disliked' => false, 'blacklisted_at' => null, 'path' => 'downloads/test3.jpg']);
    $file3->containers()->attach($container3->id);

    $result = $this->service->filterBannedContainers(collect([$file1, $file2, $file3]));

    expect($result['flaggedFileIds'])->toContain($file1->id);
    expect($result['processedIds'])->toContain($file2->id);
    expect($result['processedIds'])->toContain($file3->id);
    expect($file2->fresh()->auto_disliked)->toBeTrue();
    expect($file3->fresh()->blacklisted_at)->not->toBeNull();
});

test('handles files with multiple containers', function () {
    Bus::fake();

    $container1 = Container::factory()->create([
        'blacklisted_at' => now(),
        'action_type' => 'ui_countdown',
    ]);
    $container2 = Container::factory()->create([
        'blacklisted_at' => null,
    ]);

    $file = File::factory()->create(['auto_disliked' => false, 'blacklisted_at' => null]);
    $file->containers()->attach([$container1->id, $container2->id]);

    $result = $this->service->filterBannedContainers(collect([$file]));

    // Should use the first matched container's action type
    expect($result['flaggedFileIds'])->toContain($file->id);
});
