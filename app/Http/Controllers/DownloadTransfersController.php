<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use Illuminate\Http\JsonResponse;

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
}
