<?php

namespace App\Services;

use App\Models\File;

class DownloadedFileResetService
{
    public function __construct(
        private DownloadedFileClearService $downloadedFileClearService,
    ) {}

    /**
     * Prepare a downloaded file for a fresh download attempt.
     *
     * This is intentionally destructive: it clears the stored paths and deletes
     * existing assets from disk so DownloadFile will run again.
     */
    public function reset(File $file, bool $clearDerivedMetadata = true): void
    {
        if (! $this->downloadedFileClearService->hasStoredAssets($file)) {
            return;
        }

        $this->downloadedFileClearService->clear($file, syncSearch: false);

        $updates = [
            'not_found' => false,
        ];

        if ($clearDerivedMetadata) {
            $updates['size'] = null;
            $updates['mime_type'] = null;
            $updates['ext'] = null;
        }

        $file->forceFill($updates)->save();
    }
}
