<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes original URL in non-terminal progress payloads', function () {
    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/progress.jpg',
        'referrer_url' => 'https://example.com/art/progress',
        'downloaded_at' => null,
        'blacklisted_at' => null,
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
        ->and(array_key_exists('reaction', $payload))->toBeTrue()
        ->and($payload['reaction'])->toBeNull()
        ->and($payload['referrer_url'])->toBe('https://example.com/art/progress')
        ->and(array_key_exists('downloaded_at', $payload))->toBeTrue()
        ->and($payload['downloaded_at'])->toBeNull()
        ->and(array_key_exists('blacklisted_at', $payload))->toBeTrue()
        ->and($payload['blacklisted_at'])->toBeNull();
});

it('includes extension user reaction in extension payloads', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/reaction.jpg',
        'listing_metadata' => [
            'extension_channel' => str_repeat('a', 64),
            'extension_user_id' => $user->id,
        ],
    ]);

    Reaction::query()->create([
        'user_id' => $user->id,
        'file_id' => $file->id,
        'type' => 'funny',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://images.example.com/media/reaction.jpg',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 30,
        'last_broadcast_percent' => 30,
    ]);

    $file->refresh();
    expect(data_get($file->listing_metadata, 'extension_channel'))->toBe(str_repeat('a', 64));

    $listPayload = DownloadTransferPayload::forList($transfer);
    $queuedPayload = DownloadTransferPayload::forQueued($transfer);
    $progressPayload = DownloadTransferPayload::forProgress($transfer, 30);

    expect($listPayload['reaction'])->toBe('funny')
        ->and($queuedPayload['reaction'])->toBe('funny')
        ->and($progressPayload['reaction'])->toBe('funny');
});

it('includes null reaction for extension payloads when no reaction exists', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://images.example.com/media/no-reaction.jpg',
        'listing_metadata' => [
            'extension_channel' => str_repeat('b', 64),
            'extension_user_id' => $user->id,
        ],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://images.example.com/media/no-reaction.jpg',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => 100,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    $payload = DownloadTransferPayload::forProgress($transfer, 0);

    expect(array_key_exists('reaction', $payload))->toBeTrue()
        ->and($payload['reaction'])->toBeNull()
        ->and(array_key_exists('referrer_url', $payload))->toBeTrue()
        ->and(array_key_exists('downloaded_at', $payload))->toBeTrue()
        ->and(array_key_exists('blacklisted_at', $payload))->toBeTrue();
});

it('prefers downloaded file URLs for terminal yt-dlp payloads', function () {
    $file = File::factory()->create([
        'url' => 'https://www.youtube.com/watch?v=example',
        'downloaded' => true,
        'path' => 'downloads/aa/bb/example.mp4',
        'preview_path' => 'downloads/aa/bb/example.preview.mp4',
        'preview_url' => 'https://www.youtube.com/watch?v=example',
        'mime_type' => 'video/mp4',
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://www.youtube.com/watch?v=example',
        'domain' => 'www.youtube.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 100,
    ]);

    $payload = DownloadTransferPayload::forProgress($transfer, 100);

    $originalPath = parse_url((string) $payload['original'], PHP_URL_PATH);
    $previewPath = parse_url((string) $payload['preview'], PHP_URL_PATH);

    expect($originalPath)->toBe("/api/files/{$file->id}/downloaded")
        ->and($previewPath)->toBe("/api/files/{$file->id}/preview");
});
