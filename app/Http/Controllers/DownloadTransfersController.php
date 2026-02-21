<?php

namespace App\Http\Controllers;

use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
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
                'created_at',
                'queued_at',
                'started_at',
                'finished_at',
                'failed_at',
                'last_broadcast_percent',
                'error',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (DownloadTransfer $transfer) => DownloadTransferPayload::forList($transfer));

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
            ->with(['file:id,filename,path,url,preview_url,size,referrer_url'])
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (DownloadTransfer $transfer) => DownloadTransferPayload::forDetails($transfer));

        return response()->json(['items' => $items]);
    }
}
