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

    public const CONNECTION = 'redis-audio-metadata';

    public const QUEUE = 'audio-metadata';

    public const DEFAULT_TIMEOUT_SECONDS = 1800;

    public int $timeout;

    public function __construct(private readonly int $runId)
    {
        $this->timeout = (int) config('services.audio_metadata.queue_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        $this->onQueue((string) config('queue.connections.'.self::CONNECTION.'.queue', self::QUEUE));
    }

    public function handle(AudioMetadataProposalService $metadata): void
    {
        $metadata->processRun($this->runId);
    }
}
