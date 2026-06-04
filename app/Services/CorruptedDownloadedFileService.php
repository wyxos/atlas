<?php

namespace App\Services;

use App\Models\File;
use App\Services\Library\LibraryIndexSyncDispatcher;
use Illuminate\Validation\ValidationException;

class CorruptedDownloadedFileService
{
    public function __construct(
        private readonly DownloadedFileClearService $downloadedFileClearService,
        private readonly FileReactionService $fileReactionService,
        private readonly LibraryIndexSyncDispatcher $libraryIndexSyncDispatcher,
        private readonly MetricsService $metricsService,
    ) {}

    /**
     * @return array{deleted_file_id: int, not_found: bool}
     */
    public function markCorrupted(File $file): array
    {
        if (! $this->canMarkCorrupted($file)) {
            throw ValidationException::withMessages([
                'file' => 'Only downloaded non-local files already flagged 404 can be marked corrupted.',
            ]);
        }

        $deletedFileId = (int) $file->id;
        $notFound = (bool) $file->not_found;

        $this->downloadedFileClearService->clear($file);
        $this->fileReactionService->clearMany([$file], queueLibrarySync: false);

        $file->delete();

        $this->libraryIndexSyncDispatcher->deleteFiles([$deletedFileId]);
        $this->metricsService->syncAll();

        return [
            'deleted_file_id' => $deletedFileId,
            'not_found' => $notFound,
        ];
    }

    private function canMarkCorrupted(File $file): bool
    {
        return ! $this->isLocalSource($file)
            && (bool) $file->not_found
            && (bool) $file->downloaded
            && $this->downloadedFileClearService->hasStoredAssets($file);
    }

    private function isLocalSource(File $file): bool
    {
        return strtolower(trim((string) $file->source)) === 'local';
    }
}
