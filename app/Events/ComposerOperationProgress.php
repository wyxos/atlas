<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ComposerOperationProgress implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public string $packageName,
        public string $operation, // 'install' | 'uninstall'
        public string $status, // 'running' | 'completed' | 'failed'
        public ?string $message = null
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'composer.operation.progress';
    }

    public function broadcastWith(): array
    {
        return [
            'packageName' => $this->packageName,
            'operation' => $this->operation,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
