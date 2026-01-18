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
        return $this->payload;
    }
}
