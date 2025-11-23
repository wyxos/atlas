<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StorageProcessingProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;

    public int $total;

    public int $processed;

    public int $failed;

    public function __construct(int $userId, int $total, int $processed, int $failed)
    {
        $this->userId = $userId;
        $this->total = $total;
        $this->processed = $processed;
        $this->failed = $failed;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'storage.processing.progress';
    }

    public function broadcastWith(): array
    {
        $done = $this->processed + $this->failed;
        $progress = $this->total > 0 ? (int) floor(($done / $this->total) * 100) : 0;

        return [
            'total' => $this->total,
            'processed' => $this->processed,
            'failed' => $this->failed,
            'progress' => $progress,
        ];
    }
}
