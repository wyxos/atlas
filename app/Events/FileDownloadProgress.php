<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileDownloadProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $fileId;

    public int $progress;

    public ?int $bytesDownloaded;

    public ?int $bytesTotal;

    public ?string $status;

    public function __construct(int $fileId, int $progress, ?int $bytesDownloaded = null, ?int $bytesTotal = null, ?string $status = null)
    {
        $this->fileId = $fileId;
        $this->progress = $progress;
        $this->bytesDownloaded = $bytesDownloaded;
        $this->bytesTotal = $bytesTotal;
        $this->status = $status;
    }

    public function broadcastOn(): array
    {
        return [new Channel('file-download-progress')];
    }

    public function broadcastWith(): array
    {
        return [
            'fileId' => $this->fileId,
            'progress' => $this->progress,
            'bytesDownloaded' => $this->bytesDownloaded,
            'bytesTotal' => $this->bytesTotal,
            'status' => $this->status,
        ];
    }
}
