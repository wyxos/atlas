<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileReactionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public int $fileId,
        public bool $loved,
        public bool $liked,
        public bool $disliked,
        public bool $funny,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'file.reaction.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'file_id' => $this->fileId,
            'loved' => $this->loved,
            'liked' => $this->liked,
            'disliked' => $this->disliked,
            'funny' => $this->funny,
        ];
    }
}
