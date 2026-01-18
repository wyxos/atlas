<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns only active transfers by default', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/file.bin',
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);
    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
    ]);
    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::FAILED,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
    ]);
    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->getJson('/api/download-transfers');

    $response->assertSuccessful();

    $items = $response->json('items');
    expect($items)->toHaveCount(4);
    expect(collect($items)->pluck('status')->all())->toContain(DownloadTransferStatus::COMPLETED);
});

it('can filter by completed status', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/file.bin',
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->getJson('/api/download-transfers?status=completed');

    $response->assertSuccessful();
    $items = $response->json('items');
    expect($items)->toHaveCount(1);
    expect($items[0]['status'])->toBe(DownloadTransferStatus::COMPLETED);
});
