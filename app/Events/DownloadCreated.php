<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $downloadId,
        public int $fileId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('downloads')];
    }

    public function broadcastAs(): string
    {
        return 'downloads.created';
    }

    public function broadcastWith(): array
    {
        return [
            'downloadId' => $this->downloadId,
            'fileId' => $this->fileId,
        ];
    }
}
