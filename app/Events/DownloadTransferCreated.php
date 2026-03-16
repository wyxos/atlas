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
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('downloads')];
        $extensionChannel = data_get($this->payload, 'extension_channel');
        if (is_string($extensionChannel) && $extensionChannel !== '') {
            $channels[] = new PrivateChannel('extension-downloads.'.$extensionChannel);
        }

        return $channels;
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
