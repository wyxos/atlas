<?php

namespace App\Events;

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
        return [
            'downloadTransferId' => $this->downloadTransferId,
        ];
    }
}
