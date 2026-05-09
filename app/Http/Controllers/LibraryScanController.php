<?php

namespace App\Http\Controllers;

use App\Models\LibraryScanItem;
use App\Models\LibraryScanRun;
use App\Services\LibraryScans\LibraryScanPayload;
use App\Services\LibraryScans\LibraryScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;

class LibraryScanController extends Controller
{
    public function index(): JsonResponse
    {
        $runs = LibraryScanRun::query()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (LibraryScanRun $run): array => LibraryScanPayload::run($run));

        return response()->json([
            'items' => $runs,
        ]);
    }

    public function store(LibraryScanService $scans): JsonResponse
    {
        $run = $scans->start();

        return response()->json([
            'run' => LibraryScanPayload::run($run),
        ], 202);
    }

    public function reparseImported(LibraryScanService $scans): JsonResponse
    {
        $run = $scans->startImportedFileReparse();

        return response()->json([
            'run' => LibraryScanPayload::run($run),
        ], 202);
    }

    public function show(Request $request, LibraryScanRun $libraryScanRun): JsonResponse
    {
        $limit = min(200, max(1, $request->integer('limit', 100)));
        $encodedCursor = $request->query('cursor');
        $cursor = is_string($encodedCursor) && $encodedCursor !== ''
            ? Cursor::fromEncoded($encodedCursor)
            : null;

        $paginator = LibraryScanItem::query()
            ->where('library_scan_run_id', $libraryScanRun->id)
            ->orderByDesc('id')
            ->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        $items = $paginator->getCollection()
            ->map(fn (LibraryScanItem $item): array => LibraryScanPayload::item($item));

        return response()->json([
            'run' => LibraryScanPayload::run($libraryScanRun),
            'items' => $items,
            'pagination' => [
                'limit' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'previous_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    public function pause(LibraryScanRun $libraryScanRun, LibraryScanService $scans): JsonResponse
    {
        return response()->json([
            'run' => LibraryScanPayload::run($scans->pause($libraryScanRun)),
        ]);
    }

    public function resume(LibraryScanRun $libraryScanRun, LibraryScanService $scans): JsonResponse
    {
        return response()->json([
            'run' => LibraryScanPayload::run($scans->resume($libraryScanRun)),
        ]);
    }

    public function cancel(LibraryScanRun $libraryScanRun, LibraryScanService $scans): JsonResponse
    {
        return response()->json([
            'run' => LibraryScanPayload::run($scans->cancel($libraryScanRun)),
        ]);
    }

    public function restart(LibraryScanRun $libraryScanRun, LibraryScanService $scans): JsonResponse
    {
        return response()->json([
            'run' => LibraryScanPayload::run($scans->restart($libraryScanRun)),
        ], 202);
    }
}
