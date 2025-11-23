<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SpotifyScanProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $total,
        public int $processed,
        public bool $done = false,
        public bool $canceled = false,
        public ?string $message = null
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'spotify.scan.progress';
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
