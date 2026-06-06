<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AudioFilesChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<int>  $fileIds
     */
    public function __construct(
        public int $userId,
        public array $fileIds,
        public string $reason,
    ) {
        $this->fileIds = array_values(array_filter(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $fileIds,
        )), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'AudioFilesChanged';
    }

    /**
     * @return array{file_ids:list<int>,reason:string}
     */
    public function broadcastWith(): array
    {
        return [
            'file_ids' => $this->fileIds,
            'reason' => $this->reason,
        ];
    }
}
