<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileMarkedNotFound implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int>  $tabIds
     */
    public function __construct(
        public int $userId,
        public int $fileId,
        public array $tabIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'FileMarkedNotFound';
    }

    /**
     * @return array{fileId: int, tabIds: array<int>}
     */
    public function broadcastWith(): array
    {
        return [
            'fileId' => $this->fileId,
            'tabIds' => $this->tabIds,
        ];
    }
}
