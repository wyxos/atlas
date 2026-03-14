<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DownloadTransfersRemoved implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(public array $ids) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('downloads')];
    }

    public function broadcastAs(): string
    {
        return 'DownloadTransfersRemoved';
    }

    /**
     * @return array{ids: list<int>}
     */
    public function broadcastWith(): array
    {
        return [
            'ids' => $this->ids,
        ];
    }
}
