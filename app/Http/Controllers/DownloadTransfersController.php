<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadTransfersController extends Controller
{
    private const LIST_STATUSES = [
        DownloadTransferStatus::PENDING,
        DownloadTransferStatus::QUEUED,
        DownloadTransferStatus::PREPARING,
        DownloadTransferStatus::DOWNLOADING,
        DownloadTransferStatus::ASSEMBLING,
        DownloadTransferStatus::PREVIEWING,
        DownloadTransferStatus::PAUSED,
        DownloadTransferStatus::COMPLETED,
        DownloadTransferStatus::FAILED,
        DownloadTransferStatus::CANCELED,
    ];

    public function index(Request $request): JsonResponse
    {
        $afterId = max(0, $request->integer('after_id', 0));
        $maxId = $request->query('max_id');
        $maxId = is_numeric($maxId) ? max(0, (int) $maxId) : null;
        $perPage = min(1000, max(1, $request->integer('per_page', 100)));
        $status = $request->query('status');
        $status = is_string($status) && in_array($status, self::LIST_STATUSES, true)
            ? $status
            : null;

        $baseQuery = DownloadTransfer::query()
            ->select([
                'id',
                'file_id',
                'url',
                'status',
                'created_at',
                'queued_at',
                'started_at',
                'finished_at',
                'failed_at',
                'last_broadcast_percent',
                'error',
            ]);

        if ($status !== null) {
            $baseQuery->where('status', $status);
        }

        $total = null;
        $totalPages = null;

        if ($maxId === null) {
            $maxId = (int) ((clone $baseQuery)->max('id') ?? 0);
            $total = (clone $baseQuery)
                ->where('id', '<=', $maxId)
                ->count();
            $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 0;
        }

        $transfers = (clone $baseQuery)
            ->with(['file:id,listing_metadata,filename,path,url,referrer_url,downloaded_at,blacklisted_at'])
            ->where('id', '>', $afterId)
            ->where('id', '<=', $maxId)
            ->orderBy('id')
            ->limit($perPage + 1)
            ->get();

        $pageTransfers = $transfers->take($perPage)->values();
        $hasMore = $transfers->count() > $perPage;
        $items = DownloadTransferPayload::forListCollection($pageTransfers);

        return response()->json([
            'items' => $items,
            'cursor' => [
                'after_id' => $afterId,
                'next_after_id' => $hasMore ? (int) $pageTransfers->last()?->id : null,
                'has_more' => $hasMore,
                'max_id' => $maxId,
            ],
            'pagination' => [
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);
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
