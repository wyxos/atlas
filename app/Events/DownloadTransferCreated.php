<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadTransferCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(public array $payload) {}

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
        return 'DownloadTransferCreated';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
