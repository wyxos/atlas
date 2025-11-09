<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CivitaiMediaResolved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $fileId,
        public bool $resolved,
        public bool $notFound,
        public bool $updated,
        public ?string $message = null,
        public ?string $preview = null,
        public ?string $original = null,
        public ?string $thumbnailUrl = null,
        public ?string $trueOriginalUrl = null,
        public ?string $trueThumbnailUrl = null,
        public ?string $mimeType = null,
        public ?string $type = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('civitai-media-resolved')];
    }

    public function broadcastAs(): string
    {
        return 'civitai.media.resolved';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->fileId,
            'resolved' => $this->resolved,
            'not_found' => $this->notFound,
            'updated' => $this->updated,
            'message' => $this->message,
            'preview' => $this->preview,
            'original' => $this->original,
            'thumbnail_url' => $this->thumbnailUrl,
            'true_original_url' => $this->trueOriginalUrl,
            'true_thumbnail_url' => $this->trueThumbnailUrl,
            'mime_type' => $this->mimeType,
            'type' => $this->type,
        ];
    }
}
