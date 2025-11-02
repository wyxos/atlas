<?php

use App\Events\StorageProcessingProgress;
use App\Events\StorageScanProgress;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

it('cancels an active storage scan and clears cached state', function (): void {
    Cache::flush();
    Event::fake([StorageScanProgress::class, StorageProcessingProgress::class]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Cache::put('storage_scan:'.$user->id.':status', [
        'total' => 10,
        'processed' => 4,
        'running' => true,
        'processing_total' => 6,
    ], now()->addMinutes(10));

    Cache::put('storage_processing:'.$user->id.':total', 6, now()->addMinutes(10));
    Cache::put('storage_processing:'.$user->id.':done', 2, now()->addMinutes(10));
    Cache::put('storage_processing:'.$user->id.':failed', 1, now()->addMinutes(10));

    $this->postJson(route('storage.scan.cancel'))
        ->assertOk()
        ->assertJson(['ok' => true]);
    expect(Cache::has('storage_scan:'.$user->id.':status'))->toBeFalse();
    expect(Cache::get('storage_processing:'.$user->id.':total'))->toBeNull();
    expect(Cache::get('storage_processing:'.$user->id.':done'))->toBeNull();
    expect(Cache::get('storage_processing:'.$user->id.':failed'))->toBeNull();
    expect(Cache::get('storage_scan:'.$user->id.':cancel'))->toBeTrue();

    Event::assertDispatched(StorageScanProgress::class, function (StorageScanProgress $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->done === true
            && $event->canceled === true
            && $event->message === 'Scan canceled';
    });

    Event::assertDispatched(StorageProcessingProgress::class, function (StorageProcessingProgress $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->total === 0
            && $event->processed === 0
            && $event->failed === 0;
    });
});
