<?php

namespace App\Jobs\Downloads;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Models\DownloadChunk;
use App\Models\DownloadTransfer;
use App\Models\File;
use App\Services\Downloads\DownloadFailureMessage;
use App\Services\Downloads\DownloadTransferGeneration;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRuntimeStore;
use App\Services\Downloads\DownloadTransferTempDirectory;
use App\Services\Downloads\FileDownloadFinalizer;
use App\Services\Downloads\NativeFallbackMediaValidator;
use App\Services\Downloads\YtDlpUnsupportedUrlFallback;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AssembleDownloadTransfer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public ?int $attempt = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $downloadTransferId,
        public ?string $contentTypeHeader = null,
        ?int $attempt = null,
    ) {
        $this->attempt = $attempt;
        $this->onQueue('downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(
        FileDownloadFinalizer $finalizer,
        ?NativeFallbackMediaValidator $mediaValidator = null,
        ?DownloadTransferTempDirectory $tempDirectory = null,
    ): void {
        $mediaValidator ??= app(NativeFallbackMediaValidator::class);
        $tempDirectory ??= app(DownloadTransferTempDirectory::class);
        $this->attempt ??= 0;
        $transfer = DownloadTransfer::query()->with('file')->find($this->downloadTransferId);
        if (! $transfer || ! $transfer->file) {
            return;
        }

        $out = null;

        try {
            if (! DownloadTransferGeneration::matches($transfer, $this->attempt, [DownloadTransferStatus::DOWNLOADING])) {
                return;
            }

            $updated = DownloadTransferGeneration::update($transfer->id, $this->attempt, [DownloadTransferStatus::DOWNLOADING], [
                'status' => DownloadTransferStatus::ASSEMBLING,
            ]);
            if ($updated === 0) {
                return;
            }

            $disk = Storage::disk(config('downloads.disk'));

            $tmpDir = $tempDirectory->attempt($transfer->id, $this->attempt);
            $assembledPath = "{$tmpDir}/assembled.tmp";

            if (! $disk->exists($tmpDir)) {
                $disk->makeDirectory($tmpDir, 0755, true);
            }

            $absoluteAssembledPath = $disk->path($assembledPath);
            $out = fopen($absoluteAssembledPath, 'wb');
            if (! $out) {
                $this->failTransfer($transfer, 'Unable to open assembled output file.');

                return;
            }

            $chunks = DownloadChunk::query()
                ->where('download_transfer_id', $transfer->id)
                ->orderBy('index')
                ->get();

            foreach ($chunks as $chunk) {
                if (! DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::ASSEMBLING])) {
                    fclose($out);
                    $out = null;
                    $this->cleanupTempArtifacts($tmpDir);

                    return;
                }

                if (empty($chunk->part_path) || ! $disk->exists($chunk->part_path)) {
                    fclose($out);
                    $out = null;
                    $this->failTransfer($transfer, 'Missing chunk part file during assembly.');

                    return;
                }

                $absolutePartPath = $disk->path($chunk->part_path);
                $in = fopen($absolutePartPath, 'rb');
                if (! $in) {
                    fclose($out);
                    $out = null;
                    $this->failTransfer($transfer, 'Unable to open chunk part file during assembly.');

                    return;
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);
            $out = null;

            $nativeRejection = YtDlpUnsupportedUrlFallback::isNativeTransfer($transfer)
                ? $mediaValidator->rejectionForArtifact($absoluteAssembledPath, $this->contentTypeHeader)
                : null;
            if ($nativeRejection !== null) {
                $this->failTransfer($transfer, $nativeRejection);
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            $finalized = DownloadTransferGeneration::runLocked(
                $transfer->id,
                $this->attempt,
                [DownloadTransferStatus::ASSEMBLING],
                function (DownloadTransfer $current) use ($finalizer, $assembledPath): void {
                    $finalizer->finalize($current->file, $assembledPath, $this->contentTypeHeader, false);
                    $current->forceFill([
                        'status' => DownloadTransferStatus::PREVIEWING,
                        'last_broadcast_percent' => 100,
                        'finished_at' => null,
                        'failed_at' => null,
                        'error' => null,
                    ])->save();
                    File::query()->whereKey($current->file_id)->update([
                        'download_progress' => 100,
                        'updated_at' => now(),
                    ]);
                    app(DownloadTransferRuntimeStore::class)->forgetForTransfer($current->id);
                },
            );
            if (! $finalized) {
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            $transfer = DownloadTransferGeneration::fresh($transfer->id, $this->attempt, [DownloadTransferStatus::PREVIEWING]);
            if (! $transfer) {
                $this->cleanupTempArtifacts($tmpDir);

                return;
            }

            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($transfer, 100)
                ));
            } catch (\Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }

            GenerateTransferPreview::dispatch($transfer->id);

            $this->cleanupTempArtifacts($tmpDir);

            PumpDomainDownloads::dispatch($transfer->domain);
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }

            $this->failTransfer($transfer, $e->getMessage());
        }
    }

    private function failTransfer(DownloadTransfer $transfer, string $message): void
    {
        $failed = DownloadTransferGeneration::runLocked($transfer->id, $this->attempt, [
            DownloadTransferStatus::DOWNLOADING,
            DownloadTransferStatus::ASSEMBLING,
        ], function (DownloadTransfer $current) use ($message): void {
            $current->forceFill([
                'status' => DownloadTransferStatus::FAILED,
                'failed_at' => now(),
                'error' => DownloadFailureMessage::normalize($message),
            ])->save();
            app(DownloadTransferRuntimeStore::class)->forgetForTransfer($current->id);
        });
        if (! $failed) {
            return;
        }

        $updated = DownloadTransfer::query()->find($transfer->id);
        if ($updated) {
            try {
                event(new DownloadTransferProgressUpdated(
                    DownloadTransferPayload::forProgress($updated, (int) ($updated->last_broadcast_percent ?? 0))
                ));
            } catch (Throwable) {
                // Broadcast errors shouldn't fail downloads.
            }
        }

        PumpDomainDownloads::dispatch($transfer->domain);
    }

    private function cleanupTempArtifacts(string $tmpDir): void
    {
        $disk = Storage::disk(config('downloads.disk'));
        if ($disk->exists($tmpDir)) {
            $disk->deleteDirectory($tmpDir);
        }
    }
}
