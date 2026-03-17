<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('re-pumps the domain after pausing an active transfer', function () {
    Bus::fake();

    $user = User::factory()->create();
    $activeFile = File::factory()->create([
        'url' => 'https://example.com/active.bin',
    ]);
    $pendingFile = File::factory()->create([
        'url' => 'https://example.com/pending.bin',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $activeFile->id,
        'url' => $activeFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 25,
        'last_broadcast_percent' => 25,
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $pendingFile->id,
        'url' => $pendingFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::PENDING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/api/download-transfers/{$transfer->id}/pause")
        ->assertSuccessful();

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});

it('re-pumps the domain after canceling an active transfer', function () {
    Bus::fake();

    $user = User::factory()->create();
    $activeFile = File::factory()->create([
        'url' => 'https://example.com/active.bin',
    ]);
    $pendingFile = File::factory()->create([
        'url' => 'https://example.com/pending.bin',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $activeFile->id,
        'url' => $activeFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 25,
        'last_broadcast_percent' => 25,
    ]);

    DownloadTransfer::query()->create([
        'file_id' => $pendingFile->id,
        'url' => $pendingFile->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::PENDING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $this->actingAs($user)
        ->postJson("/api/download-transfers/{$transfer->id}/cancel")
        ->assertSuccessful();

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'example.com');
});
