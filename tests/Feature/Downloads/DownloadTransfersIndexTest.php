<?php

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

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
    expect($response->json('pagination'))->toMatchArray([
        'per_page' => 100,
        'total' => 4,
        'total_pages' => 1,
    ]);
});

it('returns cursor paginated transfer pages', function () {
    $user = User::factory()->create();
    $file = File::factory()->create([
        'url' => 'https://example.com/file.bin',
    ]);

    $firstTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file-1.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);
    $secondTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file-2.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => 100,
        'bytes_downloaded' => 10,
        'last_broadcast_percent' => 10,
    ]);
    $thirdTransfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => 'https://example.com/file-3.bin',
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::COMPLETED,
        'bytes_total' => 100,
        'bytes_downloaded' => 100,
        'last_broadcast_percent' => 100,
    ]);

    $response = $this->actingAs($user)->getJson('/api/download-transfers?after_id=0&per_page=2');

    $response->assertSuccessful();
    expect(collect($response->json('items'))->pluck('id')->all())->toBe([
        $firstTransfer->id,
        $secondTransfer->id,
    ]);
    $response->assertJson([
        'cursor' => [
            'after_id' => 0,
            'next_after_id' => $secondTransfer->id,
            'has_more' => true,
            'max_id' => $thirdTransfer->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => 3,
            'total_pages' => 2,
        ],
    ]);

    $nextChunk = $this->actingAs($user)->getJson('/api/download-transfers?after_id='.$secondTransfer->id.'&max_id='.$thirdTransfer->id.'&per_page=2');

    $nextChunk->assertSuccessful();
    expect(collect($nextChunk->json('items'))->pluck('id')->all())->toBe([
        $thirdTransfer->id,
    ]);
    $nextChunk->assertJson([
        'cursor' => [
            'after_id' => $secondTransfer->id,
            'next_after_id' => null,
            'has_more' => false,
            'max_id' => $thirdTransfer->id,
        ],
        'pagination' => [
            'per_page' => 2,
            'total' => null,
            'total_pages' => null,
        ],
    ]);
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
        'status' => DownloadTransferStatus::QUEUED,
        'bytes_total' => 100,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
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

it('batches file and reaction lookups for list responses', function () {
    $user = User::factory()->create();
    $extensionUser = User::factory()->create();
    $extensionChannel = str_repeat('c', 64);

    $file1 = File::factory()->create([
        'url' => 'https://example.com/file-1.bin',
        'listing_metadata' => [
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUser->id,
        ],
    ]);
    $file2 = File::factory()->create([
        'url' => 'https://example.com/file-2.bin',
        'listing_metadata' => [
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUser->id,
        ],
    ]);
    $file3 = File::factory()->create([
        'url' => 'https://example.com/file-3.bin',
        'listing_metadata' => [
            'extension_channel' => $extensionChannel,
            'extension_user_id' => $extensionUser->id,
        ],
    ]);

    Reaction::query()->create([
        'user_id' => $extensionUser->id,
        'file_id' => $file1->id,
        'type' => 'like',
    ]);
    Reaction::query()->create([
        'user_id' => $extensionUser->id,
        'file_id' => $file2->id,
        'type' => 'funny',
    ]);

    foreach ([$file1, $file2, $file3] as $file) {
        DownloadTransfer::query()->create([
            'file_id' => $file->id,
            'url' => (string) $file->url,
            'domain' => 'example.com',
            'status' => DownloadTransferStatus::QUEUED,
            'bytes_total' => null,
            'bytes_downloaded' => 0,
            'last_broadcast_percent' => 0,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($user)->getJson('/api/download-transfers');

    $response->assertSuccessful();

    $reactions = collect($response->json('items'))->pluck('reaction')->filter()->values()->all();
    expect($reactions)->toContain('like')
        ->and($reactions)->toContain('funny');

    $queries = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(static fn (string $query) => strtolower($query));

    $batchedFileQueries = $queries->filter(static fn (string $query) => str_contains($query, ' from ')
        && str_contains($query, 'files')
        && str_contains($query, ' in '));
    $perTransferFileQueries = $queries->filter(static fn (string $query) => str_contains($query, ' from ')
        && str_contains($query, 'files')
        && str_contains($query, 'limit 1'));

    $batchedReactionQueries = $queries->filter(static fn (string $query) => str_contains($query, ' from ')
        && str_contains($query, 'reactions')
        && str_contains($query, 'user_id')
        && str_contains($query, ' in '));
    $perTransferReactionQueries = $queries->filter(static fn (string $query) => str_contains($query, ' from ')
        && str_contains($query, 'reactions')
        && str_contains($query, 'user_id')
        && str_contains($query, 'limit 1'));

    expect($batchedFileQueries)->toHaveCount(1);
    expect($perTransferFileQueries)->toHaveCount(0);
    expect($batchedReactionQueries)->toHaveCount(1);
    expect($perTransferReactionQueries)->toHaveCount(0);
});
