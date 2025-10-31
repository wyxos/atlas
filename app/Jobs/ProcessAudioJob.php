<?php

namespace App\Jobs;

class ProcessAudioJob extends BaseProcessJob
{
    protected function process(): void
    {
        // Defer to a processor service in a follow-up step.
        // e.g., (new \App\Services\Media\AudioProcessor())->process($this->disk, $this->file);
    }
}
