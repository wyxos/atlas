<?php

namespace App\Http\Resources;

use App\Models\DownloadTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DownloadTransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DownloadTransfer $transfer */
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'file_id' => $transfer->file_id,
            'domain' => $transfer->domain,
            'url' => $transfer->url,
            'status' => $transfer->status,
            'percent' => (int) $transfer->last_broadcast_percent,
            'bytes_total' => $transfer->bytes_total,
            'bytes_downloaded' => $transfer->bytes_downloaded,
            'queued_at' => $transfer->queued_at?->toISOString(),
            'started_at' => $transfer->started_at?->toISOString(),
            'finished_at' => $transfer->finished_at?->toISOString(),
            'failed_at' => $transfer->failed_at?->toISOString(),
            'error' => $transfer->error,
            'file' => $transfer->relationLoaded('file') && $transfer->file
                ? [
                    'id' => $transfer->file->id,
                    'filename' => $transfer->file->filename,
                ]
                : null,
        ];
    }
}
