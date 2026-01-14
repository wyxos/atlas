<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadTransferProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $downloadTransferId,
        public int $fileId,
        public string $domain,
        public string $status,
        public int $percent
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('downloads'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DownloadTransferProgressUpdated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'downloadTransferId' => $this->downloadTransferId,
            'fileId' => $this->fileId,
            'domain' => $this->domain,
            'status' => $this->status,
            'percent' => $this->percent,
        ];
    }
}
