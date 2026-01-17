<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadTransfersController extends Controller
{
    public function index(): JsonResponse
    {
        $items = DownloadTransfer::query()
            ->select([
                'id',
                'status',
                'queued_at',
                'started_at',
                'finished_at',
                'last_broadcast_percent',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (DownloadTransfer $transfer) => [
                'id' => $transfer->id,
                'status' => $transfer->status,
                'queued_at' => $transfer->queued_at?->toISOString(),
                'started_at' => $transfer->started_at?->toISOString(),
                'finished_at' => $transfer->finished_at?->toISOString(),
                'percent' => (int) ($transfer->last_broadcast_percent ?? 0),
            ]);

        return response()->json(['items' => $items]);
    }

    public function details(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $ids = $validated['ids'];

        $items = DownloadTransfer::query()
            ->with(['file:id,filename,path,url,thumbnail_url,size'])
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (DownloadTransfer $transfer) => [
                'id' => $transfer->id,
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
