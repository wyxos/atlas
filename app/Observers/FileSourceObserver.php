<?php

namespace App\Observers;

use App\Models\File;
use App\Services\FileSourceRegistry;

readonly class FileSourceObserver
{
    public function __construct(private FileSourceRegistry $registry) {}

    public function created(File $file): void
    {
        $this->registry->recordCreated($file);
    }

    public function updated(File $file): void
    {
        $this->registry->recordUpdated($file);
    }

    public function deleted(File $file): void
    {
        $this->registry->recordDeleted($file);
    }

    public function restored(File $file): void
    {
        $this->registry->recordCreated($file);
    }
}
