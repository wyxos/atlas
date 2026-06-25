<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FilePreviewAssetsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $fileId) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('file-previews')];
    }

    public function broadcastAs(): string
    {
        return 'FilePreviewAssetsUpdated';
    }

    /**
     * @return array{fileId: int}
     */
    public function broadcastWith(): array
    {
        return [
            'fileId' => $this->fileId,
        ];
    }
}
