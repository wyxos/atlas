<?php

namespace App\Observers;

use App\Models\File;
use App\Models\Reaction;

class ReactionObserver
{
    public function created(Reaction $reaction): void
    {
        $this->reindexFile($reaction);
    }

    public function updated(Reaction $reaction): void
    {
        $this->reindexFile($reaction);
    }

    public function deleted(Reaction $reaction): void
    {
        $this->reindexFile($reaction);
    }

    protected function reindexFile(Reaction $reaction): void
    {
        $fileId = (int) $reaction->file_id;
        if ($fileId) {
            // Reindex the file to refresh per-user reaction arrays in Typesense
            try {
                if ($file = File::find($fileId)) {
                    $file->searchable();
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }
}
