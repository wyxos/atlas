<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setExtensionDownloadStatusApiKey(string $value, ?int $userId = null): void
{
    DB::table('settings')->updateOrInsert([
        'key' => 'extension.api_key_hash',
        'machine' => '',
    ], [
        'value' => hash('sha256', $value),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($userId !== null) {
        DB::table('settings')->updateOrInsert([
            'key' => 'extension.api_key_user_id',
            'machine' => '',
        ], [
            'value' => (string) $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

function makeTransfer(File $file, string $status, int $percent): DownloadTransfer
{
    return DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => parse_url($file->url, PHP_URL_HOST) ?: 'example.test',
        'status' => $status,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => $percent,
    ]);
}

test('download status endpoint requires a valid extension api key', function () {
    $file = File::factory()->create();
    $transfer = makeTransfer($file, DownloadTransferStatus::QUEUED, 15);
    setExtensionDownloadStatusApiKey('valid-key');

    $response = $this->postJson('/api/extension/download-status', [
        'transfer_id' => $transfer->id,
    ]);

    $response->assertUnauthorized();
});

test('download status endpoint returns transfer payload by transfer id', function () {
    $user = User::factory()->create();
    setExtensionDownloadStatusApiKey('valid-key', $user->id);

    $file = File::factory()->create([
        'downloaded_at' => now()->subMinute(),
        'blacklisted_at' => null,
    ]);
    $transfer = makeTransfer($file, DownloadTransferStatus::DOWNLOADING, 42);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-key',
    ])->postJson('/api/extension/download-status', [
        'transfer_id' => $transfer->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('transfer_id', $transfer->id);
    $response->assertJsonPath('file_id', $file->id);
    $response->assertJsonPath('status', DownloadTransferStatus::DOWNLOADING);
    $response->assertJsonPath('progress_percent', 42);
    $response->assertJsonPath('downloaded_at', $file->downloaded_at?->toIso8601String());
    $response->assertJsonPath('blacklisted_at', null);
});

test('download status endpoint resolves latest transfer by file id', function () {
    $user = User::factory()->create();
    setExtensionDownloadStatusApiKey('valid-key', $user->id);

    $file = File::factory()->create();
    makeTransfer($file, DownloadTransferStatus::QUEUED, 10);
    $latest = makeTransfer($file, DownloadTransferStatus::DOWNLOADING, 65);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-key',
    ])->postJson('/api/extension/download-status', [
        'file_id' => $file->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('transfer_id', $latest->id);
    $response->assertJsonPath('file_id', $file->id);
    $response->assertJsonPath('status', DownloadTransferStatus::DOWNLOADING);
    $response->assertJsonPath('progress_percent', 65);
});

test('download status endpoint returns 404 when transfer is missing', function () {
    $user = User::factory()->create();
    setExtensionDownloadStatusApiKey('valid-key', $user->id);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-key',
    ])->postJson('/api/extension/download-status', [
        'transfer_id' => 999_999,
    ]);

    $response->assertNotFound();
});
