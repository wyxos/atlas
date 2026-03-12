<?php

use App\Enums\DownloadTransferStatus;
use App\Jobs\Downloads\DownloadTransferYtDlp;
use App\Jobs\Downloads\GenerateTransferPreview;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadTransferRequestOptions;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\YtDlpCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function makeYtDlpTransfer(): array
{
    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/watch?v=failure-case',
        'referrer_url' => 'https://example.com/watch?v=failure-case',
        'downloaded' => false,
        'path' => null,
        'filename' => 'failure-case.mp4',
        'ext' => 'mp4',
        'mime_type' => null,
        'size' => null,
        'download_progress' => 0,
        'listing_metadata' => ['download_via' => 'yt-dlp', 'tag_name' => 'video'],
    ]);

    $transfer = DownloadTransfer::query()->create([
        'file_id' => $file->id,
        'url' => $file->url,
        'domain' => 'example.com',
        'status' => DownloadTransferStatus::DOWNLOADING,
        'bytes_total' => null,
        'bytes_downloaded' => 0,
        'last_broadcast_percent' => 0,
    ]);

    return [$file, $transfer];
}

it('fails empty yt-dlp outputs instead of finalizing zero-byte files', function () {
    Bus::fake([
        GenerateTransferPreview::class,
        PumpDomainDownloads::class,
    ]);

    [$file, $transfer] = makeYtDlpTransfer();

    $script = <<<'PHP'
$template = $argv[1] ?? '';
$output = str_replace('%(ext)s', 'mp4', $template);
$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($output, '');
PHP;

    $this->mock(YtDlpCommandBuilder::class, function (MockInterface $mock) use ($script): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturnUsing(function (string $url, string $outputTemplate, array $runtimeOptions = []) use ($script): array {
                return [PHP_BINARY, '-r', $script, $outputTemplate];
            });
    });

    (new DownloadTransferYtDlp($transfer->id))->handle(
        app(FileDownloadFinalizer::class),
        app(YtDlpCommandBuilder::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();
    $file->refresh();

    $disk = Storage::disk(config('downloads.disk'));
    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->toContain('empty output file')
        ->and($transfer->error)->toContain('Restart')
        ->and($disk->exists($tmpDir))->toBeFalse()
        ->and($file->path)->toBeNull()
        ->and($file->downloaded)->toBeFalse();

    Bus::assertDispatched(PumpDomainDownloads::class);
    Bus::assertNotDispatched(GenerateTransferPreview::class);
});

it('discards corrupt yt-dlp fragment state and keeps the transfer restartable', function () {
    Bus::fake([
        GenerateTransferPreview::class,
        PumpDomainDownloads::class,
    ]);

    [$file, $transfer] = makeYtDlpTransfer();

    $script = <<<'PHP'
$template = $argv[1] ?? '';
$output = str_replace('%(ext)s', 'mp4', $template);
$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($dir . DIRECTORY_SEPARATOR . 'download.mp4.part-Frag35.part', 'partial-fragment');
fwrite(STDERR, "WARNING: .ytdl file is corrupt. Restarting from the beginning ...\n");
fwrite(STDERR, "ERROR: The downloaded file is empty\n");
exit(1);
PHP;

    $this->mock(YtDlpCommandBuilder::class, function (MockInterface $mock) use ($script): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturnUsing(function (string $url, string $outputTemplate, array $runtimeOptions = []) use ($script): array {
                return [PHP_BINARY, '-r', $script, $outputTemplate];
            });
    });

    (new DownloadTransferYtDlp($transfer->id))->handle(
        app(FileDownloadFinalizer::class),
        app(YtDlpCommandBuilder::class),
        app(DownloadTransferRequestOptions::class),
    );

    $transfer->refresh();
    $file->refresh();

    $disk = Storage::disk(config('downloads.disk'));
    $tmpDir = rtrim((string) config('downloads.tmp_dir'), '/').'/transfer-'.$transfer->id;

    expect($transfer->status)->toBe(DownloadTransferStatus::FAILED)
        ->and($transfer->error)->toContain('.ytdl file is corrupt')
        ->and($transfer->error)->toContain('Atlas discarded the temporary yt-dlp fragments')
        ->and($disk->exists($tmpDir))->toBeFalse()
        ->and($file->path)->toBeNull()
        ->and($file->downloaded)->toBeFalse();

    Bus::assertDispatched(PumpDomainDownloads::class);
    Bus::assertNotDispatched(GenerateTransferPreview::class);
});
