<?php

namespace App\Events;

use App\Services\ExtensionRealtimeChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadTransferQueued implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload)
    {
        $this->downloadTransferId = $payload['downloadTransferId'] ?? $payload['id'] ?? null;
        $this->fileId = $payload['fileId'] ?? $payload['file_id'] ?? null;
        $this->domain = $payload['domain'] ?? null;
        $this->status = $payload['status'] ?? null;
        $this->percent = $payload['percent'] ?? null;
    }

    public ?int $downloadTransferId = null;

    public ?int $fileId = null;

    public ?string $domain = null;

    public ?string $status = null;

    public ?int $percent = null;

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('downloads')];
        $extensionChannel = ExtensionRealtimeChannel::channelName();
        if ($extensionChannel) {
            $channels[] = new PrivateChannel($extensionChannel);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'DownloadTransferQueued';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
