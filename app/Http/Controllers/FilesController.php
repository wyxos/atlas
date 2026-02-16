<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFilePreviewAssets;
use App\Listings\FileListing;
use App\Models\File;
use App\Models\Reaction;
use App\Services\MetricsService;
use App\Services\TabFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FilesController extends Controller
{
    /**
     * Display a listing of the files.
     *
     * NOTE: Do NOT use Eloquent queries for any Files listing returned to the UI.
     * The dataset is massive; always use Scout/Typesense-backed listings.
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
        // Load metadata relationship for prompt data
        $file->load(['metadata', 'autoDislikeModerationAction', 'autoBlacklistModerationAction']);
        $this->hydrateDiskMetadata($file);

        return response()->json([
            'file' => new \App\Http\Resources\FileResource($file),
        ]);
    }

    /**
     * Serve the file content.
     */
    public function serve(File $file)
    {
        if (! $file->path) {
            abort(404, 'File not found');
        }

        $fullPath = storage_path('app/'.$file->path);

        if (! file_exists($fullPath)) {
            abort(404, 'File not found');
        }

        $size = filesize($fullPath);
        $mimeType = $file->mime_type ?? 'application/octet-stream';

        $baseHeaders = [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
        ];

        $range = request()->header('Range');
        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
            $startRaw = $matches[1] ?? '';
            $endRaw = $matches[2] ?? '';

            $start = $startRaw !== '' ? (int) $startRaw : null;
            $end = $endRaw !== '' ? (int) $endRaw : null;

            // Handle suffix-byte-range-spec: bytes=-500
            if ($start === null && $end !== null) {
                $suffixLength = max(0, $end);
                if ($suffixLength === 0) {
                    return response('', 416, [
                        ...$baseHeaders,
                        'Content-Range' => "bytes */{$size}",
                    ]);
                }

                $suffixLength = min($suffixLength, $size);
                $start = $size - $suffixLength;
                $end = $size - 1;
            }

            if ($start !== null) {
                $end = $end ?? ($size - 1);

                if ($start >= $size || $start < 0 || $end < $start) {
                    return response('', 416, [
                        ...$baseHeaders,
                        'Content-Range' => "bytes */{$size}",
                    ]);
                }

                $end = min($end, $size - 1);
                $length = $end - $start + 1;

                return response()->stream(function () use ($fullPath, $start, $length) {
                    $handle = fopen($fullPath, 'rb');
                    if ($handle === false) {
                        return;
                    }

                    fseek($handle, $start);

                    $remaining = $length;
                    $chunkSize = 1024 * 64;

                    while ($remaining > 0 && ! feof($handle)) {
                        $readLength = min($chunkSize, $remaining);
                        $chunk = fread($handle, $readLength);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        echo $chunk;
                        $remaining -= strlen($chunk);

                        if (connection_aborted()) {
                            break;
                        }
                    }

                    fclose($handle);
                }, 206, [
                    ...$baseHeaders,
                    'Content-Length' => (string) $length,
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                ]);
            }
        }

        return response()->file($fullPath, [
            ...$baseHeaders,
            'Content-Length' => (string) $size,
        ]);
    }

    /**
     * Serve the downloaded file from the private downloads disk.
     */
    public function serveDownloaded(File $file)
    {
        return $this->serveDiskPath($file->path, $file->mime_type);
    }

    /**
     * Serve the generated preview from the private downloads disk.
     */
    public function servePreview(File $file)
    {
        $disk = Storage::disk(config('downloads.disk'));

        if (! $file->preview_path || ! $disk->exists($file->preview_path)) {
            $this->dispatchPreviewGeneration($file, $disk);
            abort(404, 'File not found');
        }

        $mimeType = $file->mime_type ?? 'application/octet-stream';

        return $this->serveDiskPath($file->preview_path, $mimeType);
    }

    /**
     * Serve the generated video poster from the private downloads disk.
     */
    public function serveVideoPoster(File $file)
    {
        return $this->serveDiskPath($file->poster_path, 'image/jpeg');
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
  <rect x="4" y="4" width="88" height="88" rx="18" fill="url(#bg)" stroke="rgba(148,163,184,0.25)" stroke-width="2"/>
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
    public function destroy(File $file): JsonResponse
    {
        $file->delete();

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    private function serveDiskPath(?string $path, ?string $mimeType)
    {
        if (! $path) {
            abort(404, 'File not found');
        }

        $disk = Storage::disk(config('downloads.disk'));

        if (! $disk->exists($path)) {
            abort(404, 'File not found');
        }

        $fullPath = $disk->path($path);
        $size = $disk->size($path);
        $mimeType = $mimeType ?? 'application/octet-stream';

        $baseHeaders = [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
        ];

        $range = request()->header('Range');
        if (is_string($range) && preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
            $startRaw = $matches[1] ?? '';
            $endRaw = $matches[2] ?? '';

            $start = $startRaw !== '' ? (int) $startRaw : null;
            $end = $endRaw !== '' ? (int) $endRaw : null;

            if ($start === null && $end !== null) {
                $suffixLength = max(0, $end);
                if ($suffixLength === 0) {
                    return response('', 416, [
                        ...$baseHeaders,
                        'Content-Range' => "bytes */{$size}",
                    ]);
                }

                $suffixLength = min($suffixLength, $size);
                $start = $size - $suffixLength;
                $end = $size - 1;
            }

            if ($start !== null) {
                $end = $end ?? ($size - 1);

                if ($start >= $size || $start < 0 || $end < $start) {
                    return response('', 416, [
                        ...$baseHeaders,
                        'Content-Range' => "bytes */{$size}",
                    ]);
                }

                $end = min($end, $size - 1);
                $length = $end - $start + 1;

                return response()->stream(function () use ($fullPath, $start, $length) {
                    $handle = fopen($fullPath, 'rb');
                    if ($handle === false) {
                        return;
                    }

                    fseek($handle, $start);

                    $remaining = $length;
                    $chunkSize = 1024 * 64;

                    while ($remaining > 0 && ! feof($handle)) {
                        $readLength = min($chunkSize, $remaining);
                        $chunk = fread($handle, $readLength);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        echo $chunk;
                        $remaining -= strlen($chunk);

                        if (connection_aborted()) {
                            break;
                        }
                    }

                    fclose($handle);
                }, 206, [
                    ...$baseHeaders,
                    'Content-Length' => (string) $length,
                    'Content-Range' => "bytes {$start}-{$end}/{$size}",
                ]);
            }
        }

        return response()->file($fullPath, [
            ...$baseHeaders,
            'Content-Length' => (string) $size,
        ]);
    }

    private function hydrateDiskMetadata(File $file): void
    {
        if (! $file->downloaded || ! $file->path) {
            return;
        }

        if ($file->mime_type && $file->ext && is_int($file->size) && $file->size > 0) {
            return;
        }

        $disk = Storage::disk(config('downloads.disk'));
        if (! $disk->exists($file->path)) {
            return;
        }

        $fullPath = $disk->path($file->path);

        $updates = [];

        if (! $file->ext) {
            $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
            if (is_string($ext) && $ext !== '') {
                $updates['ext'] = strtolower($ext);
            }
        }

        if (! $file->mime_type) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $fullPath) ?: null;
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '' && $mime !== 'application/octet-stream') {
                    $updates['mime_type'] = $mime;
                }
            }
        }

        if (! is_int($file->size) || $file->size <= 0) {
            $size = $disk->size($file->path);
            if (is_int($size) && $size > 0) {
                $updates['size'] = $size;
            }
        }

        if ($updates !== []) {
            $file->forceFill($updates)->save();
        }
    }

    private function dispatchPreviewGeneration(File $file, \Illuminate\Contracts\Filesystem\Filesystem $disk): void
    {
        if (! $file->downloaded || ! $file->path) {
            return;
        }

        if (! $disk->exists($file->path)) {
            return;
        }

        $lockKey = "preview-generation:{$file->id}";
        if (! Cache::add($lockKey, true, 300)) {
            return;
        }

        GenerateFilePreviewAssets::dispatch($file->id);
    }

    /**
     * Increment the preview count for a file.
     */
    public function incrementPreview(File $file): JsonResponse
    {
        $file->increment('previewed_count');
        $file->touch('previewed_at');
        $file->refresh();

        // previewed_count/previewed_at are Typesense-filterable; ensure the index stays in sync.
        $file->searchable();

        $willAutoDislike = false;

        // Check if we should auto-dislike: previewed_count >= 3, no reactions exist,
        // source is not 'local', file has no path (not on disk), and file is not blacklisted
        if ($file->previewed_count >= 3) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            $isNotLocal = $file->source !== 'local';
            $hasNoPath = empty($file->path);
            $isNotBlacklisted = $file->blacklisted_at === null;

            if (! $hasReactions && $isNotLocal && $hasNoPath && $isNotBlacklisted) {
                // Flag that we will auto-dislike (UI will show countdown)
                $willAutoDislike = true;
            }
        }

        return response()->json([
            'message' => 'Preview count incremented.',
            'previewed_count' => $file->previewed_count,
            'will_auto_dislike' => $willAutoDislike,
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
        ]);

        $fileIds = $request->input('file_ids');
        $user = Auth::user();

        // Load files and authorize
        $files = File::whereIn('id', $fileIds)->get();

        // Batch increment preview counts
        File::whereIn('id', $fileIds)->increment('previewed_count');
        File::whereIn('id', $fileIds)->update(['previewed_at' => now()]);

        // Refresh files to get updated counts
        $files->each->refresh();

        // previewed_count/previewed_at are Typesense-filterable; ensure the index stays in sync.
        $files->load(['metadata', 'reactions'])->searchable();

        // Check for will-auto-dislike candidates (previewed_count >= 3, no reactions, etc.)
        $willAutoDislikeFileIds = [];
        $candidates = $files->filter(function ($file) {
            return $file->previewed_count >= 3
                && $file->source !== 'local'
                && empty($file->path)
                && $file->blacklisted_at === null;
        });

        if ($candidates->isNotEmpty()) {
            $candidateIds = $candidates->pluck('id')->toArray();

            // Batch check which files have reactions (single query instead of N queries)
            $filesWithReactions = Reaction::whereIn('file_id', $candidateIds)
                ->pluck('file_id')
                ->toArray();

            // Filter candidates that don't have reactions
            $willAutoDislikeFileIds = array_diff($candidateIds, $filesWithReactions);
        }

        // Build response with updated counts and will-auto-dislike status
        $results = $files->map(function ($file) use ($willAutoDislikeFileIds) {
            return [
                'id' => $file->id,
                'previewed_count' => $file->previewed_count,
                'will_auto_dislike' => in_array($file->id, $willAutoDislikeFileIds),
            ];
        });

        return response()->json([
            'message' => 'Preview counts incremented.',
            'results' => $results,
        ]);
    }

    /**
     * Perform auto-dislike on multiple files (called after countdown expires).
     */
    public function batchPerformAutoDislike(\Illuminate\Http\Request $request): JsonResponse
    {
        $request->validate([
            'file_ids' => 'required|array',
            'file_ids.*' => 'required|integer|exists:files,id',
        ]);

        $fileIds = $request->input('file_ids');
        $user = Auth::user();

        // Load files and authorize
        $files = File::whereIn('id', $fileIds)->get();

        // Filter files that still meet conditions
        // Files can be auto-disliked via:
        // 1. Moderation rules (flagged at load time)
        // 2. Preview count >= 3 (flagged after previewing)
        // We don't require preview count here since moderation-flagged files
        // may not have been previewed 3 times yet
        $validFileIds = [];
        foreach ($files as $file) {
            $hasReactions = Reaction::where('file_id', $file->id)->exists();
            $isNotLocal = $file->source !== 'local';
            $hasNoPath = empty($file->path);
            $isNotBlacklisted = $file->blacklisted_at === null;
            $isNotAlreadyAutoDisliked = ! $file->auto_disliked;

            if (! $hasReactions && $isNotLocal && $hasNoPath && $isNotBlacklisted && $isNotAlreadyAutoDisliked) {
                $validFileIds[] = $file->id;
            }
        }

        if (empty($validFileIds)) {
            return response()->json([
                'message' => 'No files meet auto-dislike conditions.',
                'auto_disliked_count' => 0,
                'file_ids' => [],
            ]);
        }

        // Batch update files with auto_disliked = true
        File::whereIn('id', $validFileIds)->update(['auto_disliked' => true]);

        // Batch insert dislike reactions
        $reactionsToInsert = array_map(function ($fileId) use ($user) {
            return [
                'file_id' => $fileId,
                'user_id' => $user->id,
                'type' => 'dislike',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $validFileIds);

        // Check for existing reactions to avoid duplicates
        $existingReactions = Reaction::whereIn('file_id', $validFileIds)
            ->where('user_id', $user->id)
            ->pluck('file_id')
            ->toArray();

        $newReactionsToInsert = array_filter($reactionsToInsert, function ($reaction) use ($existingReactions) {
            return ! in_array($reaction['file_id'], $existingReactions);
        });

        if (! empty($newReactionsToInsert)) {
            app(MetricsService::class)->applyDislikeInsert(array_map(
                fn ($reaction) => (int) $reaction['file_id'],
                $newReactionsToInsert
            ));
            Reaction::insert($newReactionsToInsert);
        }

        // Detach files from all tabs belonging to this user
        app(TabFileService::class)->detachFilesFromUserTabs($user->id, $validFileIds);

        // Keep Typesense in sync (auto_disliked + dislike reaction arrays).
        File::query()
            ->whereIn('id', $validFileIds)
            ->with(['metadata', 'reactions'])
            ->get()
            ->searchable();

        return response()->json([
            'message' => 'Files auto-disliked successfully.',
            'auto_disliked_count' => count($validFileIds),
            'file_ids' => $validFileIds,
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

        // seen_count/seen_at are Typesense-filterable; ensure the index stays in sync.
        $file->searchable();

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
        $user = Auth::user();

        $deletedCount = File::count();
        File::query()->delete();

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
