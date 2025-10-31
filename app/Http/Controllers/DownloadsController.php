<?php

namespace App\Http\Controllers;

use App\Jobs\DownloadFile;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class DownloadsController extends Controller
{
    public function index(Request $request)
    {
        $downloads = Download::query()
            ->when($request->string('status')->toString() === 'completed', fn ($q) => $q->where('status', 'completed'))
            ->when($request->string('status')->toString() === 'pending', fn ($q) => $q->whereIn('status', ['in-progress', 'queued', 'paused']))
            ->when($request->string('status')->toString() === 'failed', fn ($q) => $q->where('status', 'failed'))
            ->with(['file' => function ($q) {
                $q->select('id', 'filename', 'thumbnail_url', 'url', 'path', 'downloaded', 'download_progress', 'mime_type', 'size');
            }])
            ->orderByRaw("CASE status WHEN 'in-progress' THEN 0 WHEN 'queued' THEN 1 WHEN 'paused' THEN 2 WHEN 'failed' THEN 3 WHEN 'canceled' THEN 4 ELSE 5 END")
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('downloads/Index', [
            'downloads' => $downloads,
        ]);
    }

    public function pause(Request $request, Download $download)
    {
        if (in_array($download->status, ['completed', 'canceled'], true)) {
            return response()->json(['ok' => false, 'message' => 'Download already finished.'], 422);
        }

        $download->update([
            'status' => 'paused',
            'paused_at' => now(),
            'cancel_requested_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function resume(Request $request, Download $download)
    {
        if (! in_array($download->status, ['paused', 'failed'], true)) {
            return response()->json(['ok' => false, 'message' => 'Only paused or failed downloads can be resumed.'], 422);
        }

        // Clear cancel flag on the paused record (for bookkeeping)
        $download->update([
            'cancel_requested_at' => null,
        ]);

        // Re-dispatch a new download job (will create a new Download entry on start)
        DownloadFile::dispatch($download->file);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, Download $download)
    {
        if ($download->status === 'completed') {
            return response()->json(['ok' => false, 'message' => 'Cannot cancel a completed download.'], 422);
        }

        $download->update([
            'status' => 'canceled',
            'cancel_requested_at' => now(),
            'canceled_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function retry(Request $request, Download $download)
    {
        DownloadFile::dispatch($download->file);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Download $download)
    {
        $download->delete();

        return response()->json(['ok' => true]);
    }

    public function destroyWithFile(Request $request, Download $download)
    {
        $file = $download->file;
        if ($file) {
            if (! empty($file->path)) {
                try {
                    $disk = Storage::disk('atlas_app');
                    if ($disk->exists($file->path)) {
                        $disk->delete($file->path);
                    }
                } catch (\Throwable $e) {
                    // ignore errors
                }
            }
            $file->delete();
        }

        $download->delete();

        return response()->json(['ok' => true]);
    }
}
