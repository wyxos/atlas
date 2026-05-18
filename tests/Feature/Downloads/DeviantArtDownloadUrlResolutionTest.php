<?php

use App\Jobs\DownloadFile;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DeviantArtToken;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('atlas');
    Cache::flush();
});

test('resolves DeviantArt download URL when creating a transfer', function () {
    Bus::fake();

    $user = User::factory()->create();
    DeviantArtToken::query()->create([
        'user_id' => $user->id,
        'access_token' => 'connected-access-token',
        'refresh_token' => 'connected-refresh-token',
        'scope' => 'browse user',
        'expires_at' => now()->addHour(),
    ]);

    Http::fake([
        'https://www.deviantart.com/api/v1/oauth2/deviation/download/52BAFA97-9DB9-0E5F-FF2D-C39083F89817' => Http::response([
            'src' => 'https://download.example.test/original.png',
            'filename' => 'original.png',
            'filesize' => 8192,
        ]),
    ]);

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
        'url' => 'https://fc.example.test/content.jpg',
        'referrer_url' => 'https://www.deviantart.com/artist/art/Downloadable-487117484',
        'filename' => 'listing.jpg',
        'ext' => 'jpg',
        'mime_type' => 'image/jpeg',
        'listing_metadata' => [
            'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
            'is_downloadable' => true,
        ],
        'downloaded' => false,
        'path' => null,
    ]);

    (new DownloadFile($file->id, false, ['user_id' => $user->id]))->handle();

    $file->refresh();
    $transfer = DownloadTransfer::query()->where('file_id', $file->id)->first();

    expect($file->url)->toBe('https://fc.example.test/content.jpg')
        ->and($transfer)->not->toBeNull()
        ->and($transfer?->url)->toBe('https://download.example.test/original.png')
        ->and($transfer?->domain)->toBe('download.example.test')
        ->and($transfer?->bytes_total)->toBe(8192);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://www.deviantart.com/api/v1/oauth2/deviation/download/52BAFA97-9DB9-0E5F-FF2D-C39083F89817'
        && $request->header('Authorization')[0] === 'Bearer connected-access-token');

    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'download.example.test');
});

test('falls back to the DeviantArt listing URL when no connected user is available for resolution', function () {
    Bus::fake();
    Http::fake();

    $file = File::factory()->create([
        'source' => 'deviantart.com',
        'source_id' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
        'url' => 'https://fc.example.test/content.jpg',
        'listing_metadata' => [
            'deviationid' => '52BAFA97-9DB9-0E5F-FF2D-C39083F89817',
            'is_downloadable' => true,
        ],
        'downloaded' => false,
        'path' => null,
    ]);

    (new DownloadFile($file->id))->handle();

    $transfer = DownloadTransfer::query()->where('file_id', $file->id)->first();

    expect($transfer)->not->toBeNull()
        ->and($transfer?->url)->toBe('https://fc.example.test/content.jpg')
        ->and($transfer?->domain)->toBe('fc.example.test');

    Http::assertNothingSent();
    Bus::assertDispatched(PumpDomainDownloads::class, fn (PumpDomainDownloads $job) => $job->domain === 'fc.example.test');
});
