<?php

namespace App\Jobs;

class ProcessVideoJob extends BaseProcessJob
{
    protected function process(): void
    {
        // Defer to a processor service in a follow-up step.
        // e.g., (new \App\Services\Media\VideoProcessor())->process($this->disk, $this->file);
    }
}
