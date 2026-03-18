<?php

use App\Jobs\DownloadFile;
use App\Jobs\RepairReactedBlacklistedFiles;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
    Bus::fake();
});

test('repair reacted blacklisted files dry-run does not mutate files or dispatch downloads', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '92632989',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        ],
        'blacklisted_at' => now(),
        'blacklist_reason' => 'dry-run',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [[
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
            ]],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: true);
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->blacklisted_at)->not->toBeNull()
        ->and($file->blacklist_reason)->toBe('dry-run')
        ->and($file->url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg')
        ->and(data_get($file->listing_metadata, 'url'))->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg');

    Bus::assertNotDispatched(DownloadFile::class);
});

test('repair reacted blacklisted files prefers the live civitai url, clears blacklist, and queues a download', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '92632989',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        ],
        'blacklisted_at' => now(),
        'blacklist_reason' => 'live-api',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'love',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [[
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/alternate-name.jpeg',
            ]],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->blacklisted_at)->toBeNull()
        ->and($file->blacklist_reason)->toBeNull()
        ->and($file->url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/alternate-name.jpeg')
        ->and($file->url_hash)->toBe(hash('sha256', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/alternate-name.jpeg'))
        ->and(data_get($file->listing_metadata, 'url'))->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/alternate-name.jpeg');

    Bus::assertDispatched(DownloadFile::class, function (DownloadFile $downloadJob) use ($file): bool {
        return $downloadJob->fileId === $file->id
            && $downloadJob->forceDownload === false;
    });
});

test('repair reacted blacklisted files falls back to the helper when the live api has no usable url', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '92632989',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        ],
        'blacklisted_at' => now(),
        'blacklist_reason' => 'fallback',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    $file->refresh();

    $expectedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg';

    expect($file->blacklisted_at)->toBeNull()
        ->and($file->blacklist_reason)->toBeNull()
        ->and($file->url)->toBe($expectedUrl)
        ->and($file->url_hash)->toBe(hash('sha256', $expectedUrl))
        ->and(data_get($file->listing_metadata, 'url'))->toBe($expectedUrl);

    Bus::assertDispatched(DownloadFile::class, fn (DownloadFile $downloadJob): bool => $downloadJob->fileId === $file->id);

    Bus::fake();
    app()->call([$job, 'handle']);

    Bus::assertNotDispatched(DownloadFile::class);
});

test('repair reacted blacklisted files ignores dislike-only rows', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '92632989',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'dislike-only',
    ]);

    Reaction::query()->create([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => 'dislike',
    ]);

    Http::fake();

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->blacklisted_at)->not->toBeNull()
        ->and($file->blacklist_reason)->toBe('dislike-only');

    Bus::assertNotDispatched(DownloadFile::class);
});

test('repair reacted blacklisted files queues the next chunk when more matching files remain', function () {
    $user = User::factory()->create();

    $first = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '101',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/11111111-1111-1111-1111-111111111111/width=1032/11111111-1111-1111-1111-111111111111.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/11111111-1111-1111-1111-111111111111/width=1032/11111111-1111-1111-1111-111111111111.jpeg'],
        'blacklisted_at' => now(),
    ]);
    $second = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '102',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/22222222-2222-2222-2222-222222222222/width=1032/22222222-2222-2222-2222-222222222222.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/22222222-2222-2222-2222-222222222222/width=1032/22222222-2222-2222-2222-222222222222.jpeg'],
        'blacklisted_at' => now(),
    ]);
    File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '103',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/33333333-3333-3333-3333-333333333333/width=1032/33333333-3333-3333-3333-333333333333.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/33333333-3333-3333-3333-333333333333/width=1032/33333333-3333-3333-3333-333333333333.jpeg'],
        'blacklisted_at' => now(),
    ]);

    foreach ([$first, $second] as $file) {
        Reaction::query()->create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'type' => 'like',
        ]);
    }

    Reaction::query()->create([
        'file_id' => File::query()->latest('id')->firstOrFail()->id,
        'user_id' => $user->id,
        'type' => 'funny',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 2, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    expect($first->fresh()?->blacklisted_at)->toBeNull()
        ->and($second->fresh()?->blacklisted_at)->toBeNull();

    Bus::assertDispatched(RepairReactedBlacklistedFiles::class, function (RepairReactedBlacklistedFiles $nextJob) use ($second): bool {
        return $nextJob->afterId === $second->id
            && $nextJob->chunk === 2
            && $nextJob->queueName === 'processing'
            && $nextJob->remaining === 0
            && $nextJob->dryRun === false;
    });
});

test('repair reacted blacklisted files respects the max-files limit', function () {
    $user = User::factory()->create();

    $first = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '201',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/44444444-4444-4444-4444-444444444444/width=1032/44444444-4444-4444-4444-444444444444.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/44444444-4444-4444-4444-444444444444/width=1032/44444444-4444-4444-4444-444444444444.jpeg'],
        'blacklisted_at' => now(),
    ]);
    $second = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '202',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/55555555-5555-5555-5555-555555555555/width=1032/55555555-5555-5555-5555-555555555555.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/55555555-5555-5555-5555-555555555555/width=1032/55555555-5555-5555-5555-555555555555.jpeg'],
        'blacklisted_at' => now(),
    ]);

    foreach ([$first, $second] as $file) {
        Reaction::query()->create([
            'file_id' => $file->id,
            'user_id' => $user->id,
            'type' => 'like',
        ]);
    }

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 1, dryRun: false);
    app()->call([$job, 'handle']);

    expect($first->fresh()?->blacklisted_at)->toBeNull()
        ->and($second->fresh()?->blacklisted_at)->not->toBeNull();

    Bus::assertDispatched(DownloadFile::class, fn (DownloadFile $downloadJob): bool => $downloadJob->fileId === $first->id);
    Bus::assertNotDispatched(DownloadFile::class, fn (DownloadFile $downloadJob): bool => $downloadJob->fileId === $second->id);
    Bus::assertNotDispatched(RepairReactedBlacklistedFiles::class, fn (RepairReactedBlacklistedFiles $nextJob): bool => $nextJob->afterId > 0);
});

test('repair reacted blacklisted files still clears blacklist when the canonical target already exists', function () {
    $user = User::factory()->create();
    $existingUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2/original=true/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2.jpeg';

    File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '89456574',
        'url' => $existingUrl,
        'listing_metadata' => ['url' => $existingUrl],
    ]);

    $widthFile = File::factory()->create([
        'source' => 'CivitAI',
        'source_id' => '89456574',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2/width=832/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2/width=832/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2.jpeg'],
        'blacklisted_at' => now(),
        'blacklist_reason' => 'collision',
    ]);

    Reaction::query()->create([
        'file_id' => $widthFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    Http::fake([
        'https://civitai.com/api/v1/images*' => Http::response([
            'items' => [[
                'url' => $existingUrl,
            ]],
        ], 200),
    ]);

    $job = new RepairReactedBlacklistedFiles(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    $widthFile->refresh();

    expect($widthFile->blacklisted_at)->toBeNull()
        ->and($widthFile->blacklist_reason)->toBeNull()
        ->and($widthFile->url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2/width=832/d65e7aed-6cce-4afa-8f0c-fbd11cf527e2.jpeg');

    Bus::assertDispatched(DownloadFile::class, fn (DownloadFile $downloadJob): bool => $downloadJob->fileId === $widthFile->id);
});
