<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PlaylistMembershipChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;

    public int $fileId;

    public ?int $previousPlaylistId;

    public ?int $newPlaylistId;

    public function __construct(int $userId, int $fileId, ?int $previousPlaylistId, ?int $newPlaylistId)
    {
        $this->userId = $userId;
        $this->fileId = $fileId;
        $this->previousPlaylistId = $previousPlaylistId;
        $this->newPlaylistId = $newPlaylistId;
    }

    public function broadcastOn(): array
    {
        // Use default Laravel user private channel convention
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'playlist.membership.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'file_id' => $this->fileId,
            'previous_playlist_id' => $this->previousPlaylistId,
            'new_playlist_id' => $this->newPlaylistId,
        ];
    }
}
