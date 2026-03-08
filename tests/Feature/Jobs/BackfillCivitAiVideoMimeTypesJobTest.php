<?php

use App\Jobs\BackfillCivitAiVideoMimeTypes;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('backfill civitai video mime types normalizes stale mp4 rows', function () {
    $stale = File::factory()->create([
        'source' => 'CivitAI',
        'ext' => 'mp4',
        'mime_type' => 'application/mp4',
        'url' => 'https://image.civitai.com/example/stale.mp4',
        'preview_url' => 'https://image.civitai.com/example/stale-preview.mp4',
        'listing_metadata' => [
            'type' => 'video',
        ],
    ]);

    $alreadyCorrect = File::factory()->create([
        'source' => 'CivitAI',
        'ext' => 'mp4',
        'mime_type' => 'video/mp4',
        'url' => 'https://image.civitai.com/example/correct.mp4',
    ]);

    $differentSource = File::factory()->create([
        'source' => 'extension',
        'ext' => 'mp4',
        'mime_type' => 'application/mp4',
        'url' => 'https://cdn.example.test/other.mp4',
    ]);

    $job = new BackfillCivitAiVideoMimeTypes(afterId: 0, chunk: 20, queueName: 'processing');
    app()->call([$job, 'handle']);

    expect($stale->fresh()?->mime_type)->toBe('video/mp4');
    expect($alreadyCorrect->fresh()?->mime_type)->toBe('video/mp4');
    expect($differentSource->fresh()?->mime_type)->toBe('application/mp4');
});

test('backfill civitai video mime types queues the next chunk when more matching files remain', function () {
    Bus::fake();

    $first = File::factory()->create([
        'source' => 'CivitAI',
        'ext' => 'mp4',
        'mime_type' => 'application/mp4',
        'url' => 'https://image.civitai.com/example/first.mp4',
    ]);

    $second = File::factory()->create([
        'source' => 'CivitAI',
        'ext' => 'mp4',
        'mime_type' => 'application/mp4',
        'url' => 'https://image.civitai.com/example/second.mp4',
    ]);

    File::factory()->create([
        'source' => 'CivitAI',
        'ext' => 'mp4',
        'mime_type' => 'application/mp4',
        'url' => 'https://image.civitai.com/example/third.mp4',
    ]);

    $job = new BackfillCivitAiVideoMimeTypes(afterId: 0, chunk: 2, queueName: 'maintenance');
    app()->call([$job, 'handle']);

    Bus::assertDispatched(BackfillCivitAiVideoMimeTypes::class, function (BackfillCivitAiVideoMimeTypes $job) use ($second): bool {
        return $job->afterId === $second->id
            && $job->chunk === 2
            && $job->queueName === 'maintenance';
    });

    expect($first->fresh()?->mime_type)->toBe('video/mp4');
});
