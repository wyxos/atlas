<?php

namespace App\Services;

use App\Jobs\DownloadFile;
use App\Models\File;
use Illuminate\Validation\ValidationException;

class FileRedownloadService
{
    public function __construct(
        private readonly DownloadedFileResetService $downloadedFileResetService,
        private readonly FileNotFoundService $fileNotFoundService,
    ) {}

    /**
     * @return array{queued: bool, not_found: bool, file: File}
     */
    public function redownload(File $file, int $userId): array
    {
        if (! $this->canRedownload($file)) {
            throw ValidationException::withMessages([
                'file' => 'Only downloaded non-local files that are not flagged 404 can be re-downloaded.',
            ]);
        }

        $check = $this->fileNotFoundService->reconcileRedownloadSourceCheck($file);
        if (($check['supported'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'file' => 'This file does not have a remote source URL that can be checked before re-download.',
            ]);
        }

        $file = File::query()->findOrFail($file->id);
        if ((bool) ($check['not_found'] ?? false)) {
            return [
                'queued' => false,
                'not_found' => true,
                'file' => $file,
            ];
        }

        $this->downloadedFileResetService->reset($file);
        $file->refresh();

        $this->dispatchDownloadFile((int) $file->id, $userId);

        return [
            'queued' => true,
            'not_found' => false,
            'file' => $file,
        ];
    }

    private function canRedownload(File $file): bool
    {
        return ! $this->isLocalSource($file)
            && ! (bool) $file->not_found
            && (bool) $file->downloaded
            && is_string($file->path)
            && trim($file->path) !== '';
    }

    private function isLocalSource(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === 'local';
    }

    private function dispatchDownloadFile(int $fileId, int $userId): void
    {
        DownloadFile::dispatch($fileId, false, ['user_id' => $userId])
            ->onConnection($this->asyncQueueConnection())
            ->onQueue('downloads');
    }

    private function asyncQueueConnection(): string
    {
        $connection = (string) config('downloads.queue_connection', config('queue.default', 'database'));
        $normalized = strtolower(trim($connection));

        if ($normalized === '' || $normalized === 'sync') {
            return 'database';
        }

        return $connection;
    }
}
