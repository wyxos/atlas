<?php

namespace App\Observers;

use App\Models\File;

class FileObserver
{
    public function created(File $file): void
    {
        // File observer logic can be extended here if needed
    }

    public function updated(File $file): void
    {
        // File observer logic can be extended here if needed
    }

    public function deleted(File $file): void
    {
        // File observer logic can be extended here if needed
    }
}
