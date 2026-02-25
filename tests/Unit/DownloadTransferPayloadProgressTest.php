<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes original URL in non-terminal progress payloads', function () {
    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/progress.jpg',
        'referrer_url' => 'https://example.com/art/progress',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://images.example.com/media/progress.jpg',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 25,
        'last_broadcast_percent' => 25,
    ]);

    $payload = DownloadTransferPayload::forProgress($transfer, 25);

    expect($payload['downloadTransferId'])->toBe($transfer->id)
        ->and($payload['status'])->toBe(DownloadTransferStatus::DOWNLOADING)
        ->and($payload['percent'])->toBe(25)
        ->and($payload['original'])->toBe('https://images.example.com/media/progress.jpg')
        ->and(array_key_exists('referrer_url', $payload))->toBeFalse();
});
