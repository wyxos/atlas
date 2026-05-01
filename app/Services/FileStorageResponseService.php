<?php

namespace App\Services;

use App\Jobs\GenerateFilePreviewAssets;
use App\Models\File;
use App\Support\AtlasPathResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class FileStorageResponseService
{
    private const POSITIVE_REACTION_TYPES = ['love', 'like', 'funny'];

    public function loadViewerRelations(File $file): void
    {
        $userId = Auth::id();

        $file->load([
            'metadata',
            'autoBlacklistModerationAction',
            'containers' => function ($query) use ($userId) {
                $query->withCount([
                    'files as unreacted_files_count' => function ($containerFilesQuery) use ($userId) {
                        $containerFilesQuery
                            ->whereNull('files.blacklisted_at')
                            ->where('files.not_found', false);

                        if (is_int($userId)) {
                            $containerFilesQuery->whereDoesntHave('reactions', fn ($reactionQuery) => $reactionQuery->where('user_id', $userId));

                            return;
                        }

                        $containerFilesQuery->whereDoesntHave('reactions');
                    },
                    'files as blacklisted_files_count' => fn ($containerFilesQuery) => $containerFilesQuery->whereNotNull('files.blacklisted_at'),
                    'files as positive_files_count' => function ($containerFilesQuery) use ($userId) {
                        if (! is_int($userId)) {
                            $containerFilesQuery->whereRaw('0 = 1');

                            return;
                        }

                        $containerFilesQuery->whereHas('reactions', fn ($reactionQuery) => $reactionQuery
                            ->where('user_id', $userId)
                            ->whereIn('type', self::POSITIVE_REACTION_TYPES));
                    },
                ]);
            },
        ]);
    }

    public function hydrateDiskMetadata(File $file): void
    {
        if (! $file->downloaded || ! $file->path) {
            return;
        }

        if ($file->mime_type && $file->ext && is_int($file->size) && $file->size > 0) {
            return;
        }

        $resolvedPath = AtlasPathResolver::resolveExistingPath($file->path);
        if (! $resolvedPath) {
            return;
        }

        $fullPath = $resolvedPath['full_path'];

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
            $size = $resolvedPath['size'] ?? @filesize($fullPath);
            if (is_int($size) && $size > 0) {
                $updates['size'] = $size;
            }
        }

        if ($updates !== []) {
            $file->forceFill($updates)->save();
        }
    }

    public function serve(File $file)
    {
        return $this->serveDiskPath($file->path, $file->mime_type);
    }

    public function serveDownloaded(File $file)
    {
        return $this->serveDiskPath($file->path, $file->mime_type);
    }

    public function servePreview(File $file)
    {
        if (! $file->preview_path || ! AtlasPathResolver::resolveExistingPath($file->preview_path)) {
            $this->dispatchPreviewGeneration($file, Storage::disk(config('downloads.disk')));
            abort(404, 'File not found');
        }

        $mimeType = $file->mime_type ?? 'application/octet-stream';

        return $this->serveDiskPath($file->preview_path, $mimeType);
    }

    public function serveVideoPoster(File $file)
    {
        return $this->serveDiskPath($file->poster_path, 'image/jpeg');
    }

    private function serveDiskPath(?string $path, ?string $mimeType)
    {
        $resolvedPath = AtlasPathResolver::resolveExistingPath($path);
        if (! $resolvedPath) {
            abort(404, 'File not found');
        }

        $fullPath = $resolvedPath['full_path'];
        $size = is_int($resolvedPath['size']) ? $resolvedPath['size'] : @filesize($fullPath);
        if (! is_int($size) || $size < 0) {
            abort(404, 'File not found');
        }

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

    private function dispatchPreviewGeneration(File $file, Filesystem $disk): void
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
}
