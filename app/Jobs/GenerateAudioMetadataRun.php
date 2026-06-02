<?php

namespace App\Jobs;

use App\Services\Audio\AudioMetadataProposalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateAudioMetadataRun implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(private readonly int $runId)
    {
        $this->onQueue('library-scans');
    }

    public function handle(AudioMetadataProposalService $metadata): void
    {
        $metadata->processRun($this->runId);
    }
}
