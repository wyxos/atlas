<?php

use App\Events\DownloadTransferProgressUpdated;
use App\Events\DownloadTransferQueued;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('reverb test page requires authentication', function () {
    $response = $this->get('/reverb-test');

    $response->assertRedirect('/login');
});

test('reverb test page renders for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/reverb-test');

    $response->assertSuccessful()
        ->assertSee('Reverb Integration Test');
});

test('reverb test trigger dispatches demo events', function () {
    Event::fake([
        DownloadTransferQueued::class,
        DownloadTransferProgressUpdated::class,
    ]);

    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/reverb-test/trigger', [
        'downloadTransferId' => 1234,
        'fileId' => 56789,
        'domain' => 'reverb-test',
        'status' => 'processing',
        'percent' => 55,
    ]);

    $response->assertNoContent();

    Event::assertDispatched(DownloadTransferQueued::class, function (DownloadTransferQueued $event) {
        return $event->downloadTransferId === 1234
            && $event->status === 'processing'
            && $event->percent === 55;
    });

    Event::assertDispatched(DownloadTransferProgressUpdated::class, function (DownloadTransferProgressUpdated $event) {
        return $event->downloadTransferId === 1234
            && $event->status === 'processing'
            && $event->percent === 55;
    });
});
