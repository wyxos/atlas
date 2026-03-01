<?php

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
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
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('streams yt-dlp progress and emits monotonic updates before completion', function () {
    Event::fake([DownloadTransferProgressUpdated::class]);
    Bus::fake([
        GenerateTransferPreview::class,
        PumpDomainDownloads::class,
    ]);

    $file = File::factory()->create([
        'source' => 'Extension',
        'url' => 'https://example.com/watch?v=streamed',
        'referrer_url' => 'https://example.com/watch?v=streamed',
        'downloaded' => false,
        'path' => null,
        'filename' => 'streamed-video.mp4',
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

    $script = <<<'PHP'
$template = $argv[1] ?? '';
$output = str_replace('%(ext)s', 'mp4', $template);
$dir = dirname($output);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
fwrite(STDERR, "[download]   2.5% of 10.00MiB at 1.00MiB/s ETA 00:09\n");
fwrite(STDERR, "noise that should be ignored\n");
fwrite(STDERR, "[download]  11.2% of 10.00MiB at 1.00MiB/s ETA 00:08\n");
fwrite(STDERR, "[download]  11.1% of 10.00MiB at 1.00MiB/s ETA 00:08\n");
fwrite(STDERR, "[download]  47.9% of 10.00MiB at 1.00MiB/s ETA 00:04\n");
fwrite(STDERR, "[download] 100% of 10.00MiB in 00:10\n");
file_put_contents($output, str_repeat("A", 4096));
PHP;

    $this->mock(YtDlpCommandBuilder::class, function (MockInterface $mock) use ($script): void {
        $mock->shouldReceive('build')
            ->once()
            ->andReturnUsing(function (string $url, string $outputTemplate, array $runtimeOptions = []) use ($script): array {
                return [PHP_BINARY, '-r', $script, $outputTemplate];
            });
    });

    (new DownloadTransferYtDlp($transfer->id))
        ->handle(app(FileDownloadFinalizer::class), app(YtDlpCommandBuilder::class), app(DownloadTransferRequestOptions::class));

    $transfer->refresh();
    $file->refresh();

    expect($transfer->status)->toBe(DownloadTransferStatus::PREVIEWING)
        ->and($transfer->last_broadcast_percent)->toBe(100)
        ->and($file->path)->not->toBeNull();

    $events = Event::dispatched(DownloadTransferProgressUpdated::class)
        ->map(fn (array $item) => $item[0])
        ->filter(fn (DownloadTransferProgressUpdated $event): bool => $event->downloadTransferId === $transfer->id)
        ->values();

    $percents = $events
        ->map(fn (DownloadTransferProgressUpdated $event): int => (int) $event->percent)
        ->values()
        ->all();

    expect($percents)->toContain(2, 11, 47, 100)
        ->and($percents)->toBe(array_values(array_unique($percents)))
        ->and($percents)->toBe(collect($percents)->sort()->values()->all())
        ->and($events->firstWhere('percent', 100)?->status)->toBe(DownloadTransferStatus::PREVIEWING);
});
