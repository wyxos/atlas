<?php

use App\Events\StorageProcessingProgress;
use App\Events\StorageScanProgress;
use App\Jobs\ClassifyMediaJob;
use App\Jobs\ProcessImageJob;
use App\Jobs\StorageScanJob;
use App\Models\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Cache::flush();
});

test('storage scan job dispatches classification jobs and updates scan status', function (): void {
    Bus::fake();
    Event::fake([StorageScanProgress::class, StorageProcessingProgress::class]);

    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAB7GkOtAAAAFklEQVR42mNk+M9QzwAEYBxVSFUBANcMBvc1+qsAAAAASUVORK5CYII=');
    Storage::disk('atlas')->put('images/sample.png', $png);
    Storage::disk('atlas')->put('.app/internal/ignored.png', $png);

    $job = new StorageScanJob(userId: 123, disk: 'atlas');
    $job->handle();

    Bus::assertDispatched(ClassifyMediaJob::class, function (ClassifyMediaJob $dispatched): bool {
        return $dispatched->userId === 123
            && $dispatched->disk === 'atlas'
            && $dispatched->path === 'images/sample.png';
    });

    $status = Cache::get('storage_scan:123:status');

    expect($status)->not()->toBeNull();
    expect($status['total'] ?? null)->toBe(1);
    expect($status['processed'] ?? null)->toBe(1);
    expect($status['running'] ?? null)->toBeFalse();
    expect($status['processing_total'] ?? null)->toBe(0);
    expect(Cache::get('storage_processing:123:total'))->toBe(0);

    Event::assertDispatched(StorageScanProgress::class, function (StorageScanProgress $event): bool {
        return $event->userId === 123
            && $event->done === true
            && $event->message === 'Scan complete';
    });
});

test('storage scan job reports failure when disk is unavailable', function (): void {
    Bus::fake();
    Event::fake([StorageScanProgress::class]);
    Log::spy();

    $job = new StorageScanJob(userId: 45, disk: 'missing-disk');

    $job->handle();

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return str_contains($message, 'missing-disk')
            && ($context['exception'] ?? null) instanceof \InvalidArgumentException;
    });

    Bus::assertNotDispatched(ClassifyMediaJob::class);

    $status = Cache::get('storage_scan:45:status');

    expect($status)->not()->toBeNull();
    expect($status['total'] ?? null)->toBe(0);
    expect($status['processed'] ?? null)->toBe(0);
    expect($status['running'] ?? null)->toBeFalse();

    Event::assertDispatched(StorageScanProgress::class, function (StorageScanProgress $event): bool {
        return $event->userId === 45
            && $event->done === true
            && $event->message === 'Scan failed';
    });
});

test('classify media job queues processor and increments totals', function (): void {
    Bus::fake();
    Event::fake([StorageProcessingProgress::class]);

    Storage::fake('atlas');
    Storage::fake('atlas_app');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAB7GkOtAAAAFklEQVR42mNk+M9QzwAEYBxVSFUBANcMBvc1+qsAAAAASUVORK5CYII=');
    Storage::disk('atlas')->put('images/sample.png', $png);

    Cache::put('storage_processing:123:total', 0, now()->addMinutes(10));
    Cache::put('storage_processing:123:done', 0, now()->addMinutes(10));
    Cache::put('storage_processing:123:failed', 0, now()->addMinutes(10));

    $job = new ClassifyMediaJob(userId: 123, disk: 'atlas', path: 'images/sample.png');
    $job->handle();

    Bus::assertDispatched(ProcessImageJob::class, function (ProcessImageJob $queued): bool {
        return $queued->userId === 123
            && $queued->disk === 'atlas'
            && $queued->file instanceof File
            && $queued->file->exists
            && $queued->file->path === 'images/sample.png';
    });

    expect(Cache::get('storage_processing:123:total'))->toBe(1);

    $persisted = File::query()->where('path', 'images/sample.png')->first();
    expect($persisted)->not()->toBeNull();
    expect($persisted->mime_type)->toBe('image/png');
    expect($persisted->source)->toBe('local');

    Event::assertDispatched(StorageProcessingProgress::class, function (StorageProcessingProgress $event): bool {
        return $event->userId === 123
            && $event->total === 1
            && $event->processed === 0
            && $event->failed === 0;
    });
});
