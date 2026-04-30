<?php

namespace App\Http\Controllers;

use App\Listings\FileListing;
use App\Models\File;
use App\Services\DownloadedFileClearService;
use App\Services\FileNotFoundService;
use App\Services\FileStorageResponseService;
use App\Services\Local\LocalBrowseIndexSyncService;
use App\Services\MetricsService;
use App\Services\TabFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FilesController extends Controller
{
    public function __construct(private readonly FileStorageResponseService $fileStorageResponses) {}

    /**
     * Display a listing of the files.
     *
     * Files listing reads are DB-backed so filter behavior matches the primary data store.
     */
    public function index(FileListing $listing): JsonResponse
    {
        return response()->json($listing->handle());
    }

    /**
     * Display the specified file.
     */
    public function show(File $file): JsonResponse
    {
        $this->fileStorageResponses->loadViewerRelations($file);
        $this->fileStorageResponses->hydrateDiskMetadata($file);

        return response()->json([
            'file' => new \App\Http\Resources\FileResource($file),
        ]);
    }

    /**
     * Serve the file content.
     */
    public function serve(File $file)
    {
        return $this->fileStorageResponses->serve($file);
    }

    /**
     * Serve the downloaded file from the private downloads disk.
     */
    public function serveDownloaded(File $file)
    {
        return $this->fileStorageResponses->serveDownloaded($file);
    }

    /**
     * Serve the generated preview from the private downloads disk.
     */
    public function servePreview(File $file)
    {
        return $this->fileStorageResponses->servePreview($file);
    }

    /**
     * Serve the generated video poster from the private downloads disk.
     */
    public function serveVideoPoster(File $file)
    {
        return $this->fileStorageResponses->serveVideoPoster($file);
    }

    /**
     * Serve a lightweight SVG icon for non-image/video files.
     *
     * This avoids trying to generate thumbnails for formats where we don't yet have preview support
     * (audio, documents, archives, etc.).
     */
    public function serveIcon(File $file)
    {
        $mime = strtolower((string) ($file->mime_type ?? ''));
        $ext = strtolower((string) ($file->ext ?? ''));

        $kind = match (true) {
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' || $ext === 'pdf' => 'pdf',
            str_contains($mime, 'zip') || in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz', 'bz2', 'xz'], true) => 'archive',
            str_starts_with($mime, 'text/') || in_array($ext, ['txt', 'md', 'json', 'csv', 'log'], true) => 'text',
            default => 'file',
        };

        $label = match ($kind) {
            'audio' => 'AUDIO',
            'pdf' => 'PDF',
            'archive' => 'ZIP',
            'text' => 'TXT',
            default => strtoupper(substr($ext !== '' ? $ext : 'FILE', 0, 4)),
        };

        // Simple, readable icon: document + label. Keep it self-contained for fast rendering.
        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96" role="img" aria-label="{$label} file">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0b1220"/>
      <stop offset="1" stop-color="#0f172a"/>
    </linearGradient>
  </defs>
  <rect width="96" height="96" fill="url(#bg)"/>
  <path d="M34 24h20l8 8v34a6 6 0 0 1-6 6H34a6 6 0 0 1-6-6V30a6 6 0 0 1 6-6z" fill="rgba(148,163,184,0.10)" stroke="rgba(226,232,240,0.55)" stroke-width="2" stroke-linejoin="round"/>
  <path d="M54 24v10h10" fill="none" stroke="rgba(226,232,240,0.55)" stroke-width="2" stroke-linejoin="round"/>
  <text x="48" y="62" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif" font-size="12" font-weight="700" fill="#e2e8f0" letter-spacing="0.08em">{$label}</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            // Cache aggressively; behind auth, but identical per (mime/ext) isn't worth re-requesting.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Remove the specified file from storage.
     */
    public function destroy(\Illuminate\Http\Request $request, File $file): JsonResponse
    {
        $validated = $request->validate([
            'also_from_disk' => ['sometimes', 'boolean'],
            'also_delete_record' => ['sometimes', 'boolean'],
        ]);

        $alsoFromDisk = (bool) ($validated['also_from_disk'] ?? false);
        $alsoDeleteRecord = (bool) ($validated['also_delete_record'] ?? false);

        if ($alsoFromDisk) {
            app(DownloadedFileClearService::class)->clear($file);
        }

        if (! $alsoFromDisk || $alsoDeleteRecord) {
            $deletedFileId = (int) $file->id;
            $file->delete();
            app(LocalBrowseIndexSyncService::class)->deleteFilesByIds([$deletedFileId]);

            return response()->json([
                'message' => $alsoFromDisk
                    ? 'File deleted from disk and record deleted.'
                    : 'File deleted successfully.',
            ]);
        }

        $this->fileStorageResponses->loadViewerRelations($file);

        return response()->json([
            'message' => 'File deleted from disk. Record kept.',
            'file' => new \App\Http\Resources\FileResource($file),
        ]);
    }

    /**
     * Increment the preview count for a file.
     */
    public function incrementPreview(File $file): JsonResponse
    {
        $file->increment('previewed_count');
        $file->touch('previewed_at');
        $file->refresh();
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds([$file->id]);

        return response()->json([
            'message' => 'Preview count incremented.',
            'previewed_count' => $file->previewed_count,
        ]);
    }

    /**
     * Batch increment preview counts for multiple files.
     */
    public function batchIncrementPreview(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
            'increments' => 'nullable|integer|min:1|max:50',
        ]);

        $fileIds = $request->input('file_ids');
        $increments = (int) $request->input('increments', 1);
        // Load files and authorize
        $files = File::whereIn('id', $fileIds)->get();

        // Batch increment preview counts
        File::whereIn('id', $fileIds)->increment('previewed_count', $increments);
        File::whereIn('id', $fileIds)->update(['previewed_at' => now()]);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds(array_map('intval', $fileIds));

        // Refresh files to get updated counts
        $files->each(fn (File $file) => $file->refresh());

        $results = $files->map(function ($file) {
            return [
                'id' => $file->id,
                'previewed_count' => $file->previewed_count,
            ];
        });

        return response()->json([
            'message' => 'Preview counts incremented.',
            'results' => $results,
        ]);
    }

    /**
     * Batch manually blacklist multiple files.
     */
    public function batchBlacklist(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = collect($request->input('file_ids'))
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->unique()
            ->values()
            ->all();
        $files = File::query()
            ->whereIn('id', $fileIds)
            ->with(['metadata', 'reactions'])
            ->get()
            ->keyBy('id');
        $newBlacklistIds = $files
            ->filter(fn (File $file): bool => $file->blacklisted_at === null)
            ->keys()
            ->map(fn (mixed $fileId): int => (int) $fileId)
            ->values()
            ->all();
        $processedIds = $newBlacklistIds;

        if ($processedIds === []) {
            return response()->json([
                'message' => 'No files were blacklisted.',
                'results' => [],
            ]);
        }

        $blacklistedAt = now();
        DB::transaction(function () use ($files, $newBlacklistIds, $blacklistedAt): void {
            if ($newBlacklistIds === []) {
                return;
            }

            $metrics = app(MetricsService::class);
            $metrics->applyBlacklistAdd($newBlacklistIds);

            foreach ($files->only($newBlacklistIds) as $file) {
                $metrics->applyAutoDislikeClear($file);

                foreach ($file->reactions as $reaction) {
                    $metrics->applyReactionChange($file, $reaction->type, null, false, true);
                    $reaction->delete();
                }
            }

            File::query()
                ->whereIn('id', $newBlacklistIds)
                ->update([
                    'blacklisted_at' => $blacklistedAt,
                    'auto_disliked' => false,
                ]);
        });

        app(LocalBrowseIndexSyncService::class)->syncFilesByIds($processedIds);
        app(LocalBrowseIndexSyncService::class)->syncReactionsForFileIds($processedIds);

        $user = Auth::user();
        if ($user) {
            app(TabFileService::class)->detachFilesFromUserTabs($user->id, $processedIds);
        }

        return response()->json([
            'message' => 'Files blacklisted successfully.',
            'results' => array_map(
                static fn (int $fileId): array => [
                    'id' => $fileId,
                    'blacklisted_at' => $blacklistedAt->toIso8601String(),
                ],
                $processedIds
            ),
        ]);
    }

    /**
     * Reset preview counts for multiple files.
     */
    public function batchResetPreview(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = $request->input('file_ids');

        File::whereIn('id', $fileIds)->update([
            'previewed_count' => 0,
            'previewed_at' => null,
        ]);
        app(LocalBrowseIndexSyncService::class)->syncFilesByIds(array_map('intval', $fileIds));

        $files = File::query()
            ->whereIn('id', $fileIds)
            ->with(['metadata', 'reactions'])
            ->get();

        return response()->json([
            'message' => 'Preview counts reset.',
            'results' => $files->map(static fn (File $file): array => [
                'id' => $file->id,
                'previewed_count' => (int) $file->previewed_count,
            ]),
        ]);
    }

    /**
     * Report that a remote preview failed to load in the masonry grid.
     */
    public function reportPreviewFailure(File $file): JsonResponse
    {
        $result = app(FileNotFoundService::class)->reconcilePreviewFailure($file);
        $currentUserId = (int) Auth::id();
        $currentUserTabIds = collect($result['affected_tabs_by_user'] ?? [])
            ->firstWhere('user_id', $currentUserId)['tab_ids'] ?? [];

        return response()->json([
            'fileId' => (int) ($result['file_id'] ?? $file->id),
            'notFound' => (bool) ($result['not_found'] ?? false),
            'tabIds' => array_values(array_map('intval', $currentUserTabIds)),
        ]);
    }

    /**
     * Increment the seen count for a file.
     */
    public function incrementSeen(File $file): JsonResponse
    {
        $file->increment('seen_count');
        $file->touch('seen_at');
        $file->refresh();

        return response()->json([
            'message' => 'Seen count incremented.',
            'seen_count' => $file->seen_count,
        ]);
    }

    /**
     * Delete all files in the database and empty atlas app storage.
     */
    public function deleteAll(): JsonResponse
    {
        $deletedCount = File::count();
        File::query()->delete();
        app(LocalBrowseIndexSyncService::class)->deleteAll();

        // Empty atlas app storage (including directory structure)
        $atlasDisk = Storage::disk('atlas-app');

        // Delete all top-level directories (downloads/, thumbnails/, and any future ones)
        foreach ($atlasDisk->directories() as $directory) {
            $atlasDisk->deleteDirectory($directory);
        }

        // Delete any remaining top-level files, but keep dotfiles like .gitignore
        foreach ($atlasDisk->files() as $file) {
            if (str_starts_with(basename($file), '.')) {
                continue;
            }

            $atlasDisk->delete($file);
        }

        // Also clear private directory (where File model stores files)
        $localDisk = Storage::disk('local');
        if ($localDisk->exists('private')) {
            $localDisk->deleteDirectory('private');
        }

        return response()->json([
            'message' => 'All files deleted successfully.',
            'deleted_count' => $deletedCount,
        ]);
    }
}
