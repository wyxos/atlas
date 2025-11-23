<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StorageScanProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;

    public int $total;

    public int $processed;

    public bool $done;

    public bool $canceled;

    public ?string $message;

    public function __construct(int $userId, int $total, int $processed, bool $done = false, bool $canceled = false, ?string $message = null)
    {
        $this->userId = $userId;
        $this->total = $total;
        $this->processed = $processed;
        $this->done = $done;
        $this->canceled = $canceled;
        $this->message = $message;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'storage.scan.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'total' => $this->total,
            'processed' => $this->processed,
            'done' => $this->done,
            'canceled' => $this->canceled,
            'message' => $this->message,
        ];
    }
}
