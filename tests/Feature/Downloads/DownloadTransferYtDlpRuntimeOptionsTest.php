<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('passes runtime cookies and user agent to yt-dlp command builder', function () {
    $file = File::factory()->create([
        'url' => 'https://x.com/example/status/123456',
        'referrer_url' => 'https://x.com/example/status/123456',
        'downloaded' => false,
        'path' => null,
        'filename' => 'video.mp4',
        'ext' => 'mp4',
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'x.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    app(DownloadTransferRuntimeStore::class)->putForTransfer($transfer->id, [
        'cookies' => [
            [
                'name' => 'auth_token',
                'value' => 'abc123',
                'domain' => 'x.com',
                'path' => '/',
                'secure' => true,
                'http_only' => true,
                'host_only' => false,
                'expires_at' => time() + 3600,
            ],
            [
                'name' => 'ct0',
                'value' => 'def456',
                'domain' => 'x.com',
                'path' => '/',
                'secure' => true,
                'http_only' => false,
                'host_only' => false,
                'expires_at' => time() + 3600,
            ],
            [
                'name' => 'other',
                'value' => 'zzz',
                'domain' => 'example.com',
                'path' => '/',
                'secure' => true,
                'http_only' => false,
                'host_only' => false,
                'expires_at' => time() + 3600,
            ],
        ],
        'user_agent' => 'AtlasExtensionRuntime/2.0',
    ]);

    $capturedRuntimeOptions = [];
    $capturedCookieJarContents = '';

    $script = <<<'PHP'
$template = $argv[1] ?? '';
$output = str_replace('%(ext)s', 'mp4', $template);
$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($output, str_repeat("A", 1024));
PHP;

    $this->mock(YtDlpCommandBuilder::class, function (MockInterface $mock) use (&$capturedRuntimeOptions, &$capturedCookieJarContents, $script): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturnUsing(function (string $url, string $outputTemplate, array $runtimeOptions = []) use (&$capturedRuntimeOptions, &$capturedCookieJarContents, $script): array {
                $capturedRuntimeOptions = $runtimeOptions;
                $cookieJarPath = $runtimeOptions['cookies_path'] ?? null;
                if (is_string($cookieJarPath) && $cookieJarPath !== '' && is_file($cookieJarPath)) {
                    $capturedCookieJarContents = (string) file_get_contents($cookieJarPath);
                }

                return [PHP_BINARY, '-r', $script, $outputTemplate];
            });
    });

    (new DownloadTransferYtDlp($transfer->id))->handle(
        app(FileDownloadFinalizer::class),
        app(YtDlpCommandBuilder::class),
        app(DownloadTransferRequestOptions::class),
    );

    expect($capturedRuntimeOptions['user_agent'] ?? null)->toBe('AtlasExtensionRuntime/2.0')
        ->and($capturedRuntimeOptions['cookies_path'] ?? null)->not->toBeNull()
        ->and($capturedCookieJarContents)->toContain('auth_token')
        ->and($capturedCookieJarContents)->toContain('ct0')
        ->and($capturedCookieJarContents)->not->toContain('other');
});
