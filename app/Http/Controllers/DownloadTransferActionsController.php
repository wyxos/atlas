<?php

namespace App\Http\Controllers;

use App\Enums\DownloadTransferStatus;
use App\Events\DownloadTransferProgressUpdated;
use App\Jobs\Downloads\PumpDomainDownloads;
use App\Jobs\Downloads\PumpDomainDownloadsAfterYtDlpRelease;
use App\Jobs\Downloads\RemoveDownloadTransfers;
use App\Models\DownloadTransfer;
use App\Services\Downloads\DownloadTransferActionAvailability;
use App\Services\Downloads\DownloadTransferActionSupport;
use App\Services\Downloads\DownloadTransferActionTransition;
use App\Services\Downloads\DownloadTransferPayload;
use App\Services\Downloads\DownloadTransferRemovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DownloadTransferActionsController extends Controller
{
    public function __construct(
        private readonly DownloadTransferRemovalService $transferRemovalService,
        private readonly DownloadTransferActionSupport $actionSupport,
        private readonly DownloadTransferActionTransition $actionTransition,
    ) {}

    public function pause(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $this->actionSupport->canPause($downloadTransfer)) {
            return response()->json([
                'message' => 'Download is not active.',
            ], 409);
        }

        $releasedDomain = $downloadTransfer->domain;
        if (! $this->actionTransition->pause($downloadTransfer)) {
            return response()->json(['message' => 'Download is no longer active.'], 409);
        }
        $this->broadcastState($downloadTransfer);
        $this->dispatchPumpIfReady($downloadTransfer, $releasedDomain);

        return response()->json([
            'message' => 'Download paused.',
        ]);
    }

    public function resume(Request $request, DownloadTransfer $downloadTransfer): JsonResponse
    {
        $downloadTransfer->loadMissing('file');

        if (! DownloadTransferActionAvailability::canResume($downloadTransfer)) {
            return response()->json([
                'message' => $downloadTransfer->status === DownloadTransferStatus::FAILED
                    ? 'Download cannot resume. Use Restart to fetch it from scratch.'
                    : 'Download is not resumable.',
            ], 409);
        }

        $releasedDomain = $downloadTransfer->domain;
        $resumed = $downloadTransfer->status === DownloadTransferStatus::PAUSED
            ? $this->actionTransition->resumeFromScratch($downloadTransfer, ['user_id' => (int) $request->user()->id])
            : $this->actionTransition->resumeFailed($downloadTransfer);
        if (! $resumed) {
            return response()->json(['message' => 'Download is no longer resumable.'], 409);
        }

        $this->broadcastState($downloadTransfer);

        $this->dispatchPumpIfReady($downloadTransfer, $releasedDomain);

        return response()->json([
            'message' => 'Download resumed.',
        ]);
    }

    public function cancel(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! $this->actionSupport->canCancel($downloadTransfer)) {
            return response()->json([
                'message' => 'Download is already finished.',
            ], 409);
        }

        $releasedDomain = $downloadTransfer->domain;
        if (! $this->actionTransition->cancel($downloadTransfer)) {
            return response()->json(['message' => 'Download is no longer cancelable.'], 409);
        }
        $this->broadcastState($downloadTransfer);
        $this->dispatchPumpIfReady($downloadTransfer, $releasedDomain);

        return response()->json([
            'message' => 'Download canceled.',
        ]);
    }

    public function pauseBatchTransfers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $transfers = DownloadTransfer::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $pausedIds = [];
        $skippedIds = [];

        foreach ($transfers as $transfer) {
            if (! $this->actionSupport->canPause($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }

            $releasedDomain = $transfer->domain;
            if (! $this->actionTransition->pause($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }
            $this->broadcastState($transfer);
            $this->dispatchPumpIfReady($transfer, $releasedDomain);
            $pausedIds[] = $transfer->id;
        }

        return response()->json([
            'message' => 'Downloads paused.',
            'ids' => $pausedIds,
            'skipped_ids' => $skippedIds,
        ]);
    }

    public function cancelBatchTransfers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
        ]);

        $transfers = DownloadTransfer::query()
            ->whereIn('id', $validated['ids'])
            ->get();

        $canceledIds = [];
        $skippedIds = [];

        foreach ($transfers as $transfer) {
            if (! $this->actionSupport->canCancel($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }

            $releasedDomain = $transfer->domain;
            if (! $this->actionTransition->cancel($transfer)) {
                $skippedIds[] = $transfer->id;

                continue;
            }
            $this->broadcastState($transfer);
            $this->dispatchPumpIfReady($transfer, $releasedDomain);
            $canceledIds[] = $transfer->id;
        }

        return response()->json([
            'message' => 'Downloads canceled.',
            'ids' => $canceledIds,
            'skipped_ids' => $skippedIds,
        ]);
    }

    public function restart(Request $request, DownloadTransfer $downloadTransfer): JsonResponse
    {
        if (! in_array($downloadTransfer->status, [
            DownloadTransferStatus::FAILED,
            DownloadTransferStatus::CANCELED,
            DownloadTransferStatus::COMPLETED,
        ], true)) {
            return response()->json([
                'message' => 'Download is not restartable.',
            ], 409);
        }

        $releasedDomain = $downloadTransfer->domain;
        if (! $this->actionTransition->restart($downloadTransfer, ['user_id' => (int) $request->user()->id])) {
            return response()->json(['message' => 'Download is no longer restartable.'], 409);
        }

        $this->broadcastState($downloadTransfer);

        $this->dispatchPumpIfReady($downloadTransfer, $releasedDomain);

        return response()->json([
            'message' => 'Download restarted.',
        ]);
    }

    public function destroy(DownloadTransfer $downloadTransfer): JsonResponse
    {
        if ($this->actionSupport->shouldQueueSingleRemoval($downloadTransfer)) {
            RemoveDownloadTransfers::dispatch(ids: [$downloadTransfer->id]);

            return response()->json([
                'message' => 'Download removal queued.',
                'ids' => [$downloadTransfer->id],
                'count' => 1,
                'queued' => true,
            ]);
        }

        $removedIds = $this->transferRemovalService->remove($downloadTransfer);
        $this->actionSupport->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Download removed.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyWithDisk(DownloadTransfer $downloadTransfer): JsonResponse
    {
        $validated = request()->validate([
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $alsoDeleteRecord = (bool) ($validated['also_delete_record'] ?? false);

        if ($this->actionSupport->shouldQueueSingleRemoval($downloadTransfer)) {
            RemoveDownloadTransfers::dispatch(
                ids: [$downloadTransfer->id],
                alsoFromDisk: true,
                alsoDeleteRecord: $alsoDeleteRecord,
            );

            return response()->json([
                'message' => 'Download removal queued.',
                'ids' => [$downloadTransfer->id],
                'count' => 1,
                'queued' => true,
            ]);
        }

        $removedIds = $this->transferRemovalService->remove($downloadTransfer, true, $alsoDeleteRecord);
        $this->actionSupport->broadcastRemoved($removedIds);

        return response()->json([
            'message' => $alsoDeleteRecord
                ? 'Download removed, file deleted from disk, and Atlas record deleted.'
                : 'Download removed and file deleted from disk.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer|exists:download_transfers,id',
            'also_from_disk' => 'sometimes|boolean',
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $ids = $validated['ids'];
        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = $alsoFromDisk && (bool) ($validated['also_delete_record'] ?? false);

        if ($this->actionSupport->shouldQueueBulkRemoval(count($ids)) || $this->actionSupport->shouldQueueRemovalByIds($ids)) {
            RemoveDownloadTransfers::dispatch(
                ids: $ids,
                alsoFromDisk: $alsoFromDisk,
                alsoDeleteRecord: $alsoDeleteRecord,
            );

            return response()->json([
                'message' => 'Download removal queued.',
                'count' => count($ids),
                'queued' => true,
            ]);
        }

        $removedIds = [];
        $this->transferRemovalService->removeByIds($ids, $alsoFromDisk, $alsoDeleteRecord, function (array $chunkIds) use (&$removedIds): void {
            $removedIds = [...$removedIds, ...$chunkIds];
        });
        $this->actionSupport->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Downloads removed.',
            'ids' => $removedIds,
            'count' => count($removedIds),
            'queued' => false,
        ]);
    }

    public function destroyCompleted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'also_from_disk' => 'sometimes|boolean',
            'also_delete_record' => 'sometimes|boolean',
        ]);

        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = $alsoFromDisk && (bool) ($validated['also_delete_record'] ?? false);
        $completedCount = $this->transferRemovalService->completedCount();

        if ($completedCount === 0) {
            return response()->json([
                'message' => 'Completed downloads removed.',
                'count' => 0,
                'ids' => [],
                'queued' => false,
            ]);
        }

        if ($this->actionSupport->shouldQueueBulkRemoval($completedCount)) {
            RemoveDownloadTransfers::dispatch(
                alsoFromDisk: $alsoFromDisk,
                alsoDeleteRecord: $alsoDeleteRecord,
                completedOnly: true,
            );

            return response()->json([
                'message' => 'Completed download removal queued.',
                'count' => $completedCount,
                'queued' => true,
            ]);
        }

        $removedIds = [];
        $removedCount = $this->transferRemovalService->removeCompleted($alsoFromDisk, $alsoDeleteRecord, function (array $chunkIds) use (&$removedIds): void {
            $removedIds = [...$removedIds, ...$chunkIds];
        });
        $this->actionSupport->broadcastRemoved($removedIds);

        return response()->json([
            'message' => 'Completed downloads removed.',
            'count' => $removedCount,
            'ids' => $removedIds,
            'queued' => false,
        ]);
    }

    private function broadcastState(DownloadTransfer $downloadTransfer): void
    {
        $downloadTransfer->refresh();

        try {
            event(new DownloadTransferProgressUpdated(
                DownloadTransferPayload::forProgress($downloadTransfer, (int) ($downloadTransfer->last_broadcast_percent ?? 0))
            ));
        } catch (\Throwable) {
            // Broadcast errors shouldn't fail download actions.
        }
    }

    private function dispatchPumpIfReady(DownloadTransfer $downloadTransfer, string $releasedDomain): void
    {
        if ($this->actionTransition->shouldDeferPump($downloadTransfer)) {
            PumpDomainDownloadsAfterYtDlpRelease::dispatch($downloadTransfer->id, $releasedDomain)
                ->delay(now()->addSeconds(5));

            return;
        }

        foreach (array_unique(array_filter([$releasedDomain, $downloadTransfer->domain])) as $domain) {
            PumpDomainDownloads::dispatch($domain);
        }
    }
}
