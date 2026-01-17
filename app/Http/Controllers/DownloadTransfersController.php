<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Http\JsonResponse;

class DownloadTransfersController extends Controller
{
    public function index(): JsonResponse
    {
        $items = DownloadTransfer::query()
            ->with(['file:id,filename,path,url,thumbnail_url,size'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (DownloadTransfer $transfer) => [
                'id' => $transfer->id,
                'status' => $transfer->status,
                'queued_at' => $transfer->queued_at?->toISOString(),
                'started_at' => $transfer->started_at?->toISOString(),
                'finished_at' => $transfer->finished_at?->toISOString(),
                'percent' => (int) ($transfer->last_broadcast_percent ?? 0),
                ...$this->filePayload($transfer->file, $transfer->url),
            ]);

        return response()->json(['items' => $items]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filePayload(?File $file, ?string $sourceUrl): array
    {
        $original = $sourceUrl;
        if (! $original && $file?->url) {
            $original = $file->url;
        }
        if (! $original && $file?->path) {
            $original = route('api.files.serve', ['file' => $file->id]);
        }

        $preview = $file?->thumbnail_url ?? $original;

        return [
            'path' => $file?->path,
            'original' => $original,
            'preview' => $preview,
            'size' => $file?->size,
            'filename' => $file?->filename,
        ];
    }
}
