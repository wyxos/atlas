<?php

namespace App\Events;

use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadTransferQueued implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $downloadTransferId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('downloads'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DownloadTransferQueued';
    }

    public function broadcastWith(): array
    {
        $transfer = DownloadTransfer::query()
            ->with(['file:id,filename,path,url,thumbnail_url,size'])
            ->find($this->downloadTransferId);

        if (! $transfer) {
            return [
                'downloadTransferId' => $this->downloadTransferId,
            ];
        }

        return [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'percent' => (int) ($transfer->last_broadcast_percent ?? 0),
            ...$this->filePayload($transfer->file, $transfer->url),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filePayload(?File $file, ?string $sourceUrl): array
    {
        $original = $sourceUrl;
        if (! $original && $file?->url) {
            $original = $file->url;
        }
        if (! $original && $file?->path) {
            $original = route('api.files.serve', ['file' => $file->id]);
        }

        $preview = $file?->thumbnail_url ?? $original;

        return [
            'path' => $file?->path,
            'original' => $original,
            'preview' => $preview,
            'size' => $file?->size,
            'filename' => $file?->filename,
        ];
    }
}
