<?php

use App\Jobs\RepairCivitAiImageUrls;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('scout.driver', 'null');
    Bus::fake();
});

test('repair civitai image urls dry-run does not mutate files', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=450/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
            'title' => 'dry-run',
        ],
    ]);

    $job = new RepairCivitAiImageUrls(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: true);
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg')
        ->and($file->url_hash)->toBe(hash('sha256', 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg'))
        ->and(data_get($file->listing_metadata, 'url'))->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg')
        ->and($file->preview_url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=450/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg');
});

test('repair civitai image urls updates url hashes and listing metadata on a real run', function () {
    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'preview_url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=450/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
        'listing_metadata' => [
            'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=1032/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg',
            'title' => 'real-run',
        ],
    ]);

    $job = new RepairCivitAiImageUrls(afterId: 0, chunk: 20, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    $file->refresh();

    $expectedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/original=true/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg';

    expect($file->url)->toBe($expectedUrl)
        ->and($file->url_hash)->toBe(hash('sha256', $expectedUrl))
        ->and(data_get($file->listing_metadata, 'url'))->toBe($expectedUrl)
        ->and(data_get($file->listing_metadata, 'title'))->toBe('real-run')
        ->and($file->preview_url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/13f5163c-a27e-4d39-95ec-bd2af736a08b/width=450/13f5163c-a27e-4d39-95ec-bd2af736a08b.jpeg');

    $updatedAt = $file->updated_at?->copy();

    Bus::fake();
    app()->call([$job, 'handle']);

    $file->refresh();

    expect($file->url)->toBe($expectedUrl)
        ->and($file->url_hash)->toBe(hash('sha256', $expectedUrl))
        ->and(data_get($file->listing_metadata, 'url'))->toBe($expectedUrl)
        ->and($file->updated_at?->toJSON())->toBe($updatedAt?->toJSON());
});

test('repair civitai image urls queues the next chunk when more matching files remain', function () {
    $first = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/11111111-1111-1111-1111-111111111111/width=1032/11111111-1111-1111-1111-111111111111.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/11111111-1111-1111-1111-111111111111/width=1032/11111111-1111-1111-1111-111111111111.jpeg'],
    ]);

    $second = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/22222222-2222-2222-2222-222222222222/width=1032/22222222-2222-2222-2222-222222222222.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/22222222-2222-2222-2222-222222222222/width=1032/22222222-2222-2222-2222-222222222222.jpeg'],
    ]);

    File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/33333333-3333-3333-3333-333333333333/width=1032/33333333-3333-3333-3333-333333333333.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/33333333-3333-3333-3333-333333333333/width=1032/33333333-3333-3333-3333-333333333333.jpeg'],
    ]);

    $job = new RepairCivitAiImageUrls(afterId: 0, chunk: 2, queueName: 'processing', remaining: 0, dryRun: false);
    app()->call([$job, 'handle']);

    Bus::assertDispatched(RepairCivitAiImageUrls::class, function (RepairCivitAiImageUrls $nextJob) use ($second): bool {
        return $nextJob->afterId === $second->id
            && $nextJob->chunk === 2
            && $nextJob->queueName === 'processing'
            && $nextJob->remaining === 0
            && $nextJob->dryRun === false;
    });

    expect($first->fresh()?->url)->toEndWith('/original=true/11111111-1111-1111-1111-111111111111.jpeg')
        ->and($second->fresh()?->url)->toEndWith('/original=true/22222222-2222-2222-2222-222222222222.jpeg');
});

test('repair civitai image urls respects the max-files limit', function () {
    $first = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/44444444-4444-4444-4444-444444444444/width=1032/44444444-4444-4444-4444-444444444444.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/44444444-4444-4444-4444-444444444444/width=1032/44444444-4444-4444-4444-444444444444.jpeg'],
    ]);

    $second = File::factory()->create([
        'source' => 'CivitAI',
        'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/55555555-5555-5555-5555-555555555555/width=1032/55555555-5555-5555-5555-555555555555.jpeg',
        'listing_metadata' => ['url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/55555555-5555-5555-5555-555555555555/width=1032/55555555-5555-5555-5555-555555555555.jpeg'],
    ]);

    $job = new RepairCivitAiImageUrls(afterId: 0, chunk: 20, queueName: 'processing', remaining: 1, dryRun: false);
    app()->call([$job, 'handle']);

    expect($first->fresh()?->url)->toEndWith('/original=true/44444444-4444-4444-4444-444444444444.jpeg')
        ->and($second->fresh()?->url)->toBe('https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/55555555-5555-5555-5555-555555555555/width=1032/55555555-5555-5555-5555-555555555555.jpeg');

    Bus::assertNotDispatched(RepairCivitAiImageUrls::class, function (RepairCivitAiImageUrls $nextJob): bool {
        return $nextJob->afterId > 0;
    });
});
