<?php

namespace App\Jobs;

use App\Enums\ModerationFeedRemovalRunStatus;
use App\Models\ModerationFeedRemovalRun;
use App\Services\Moderation\FeedRemovalBackfillService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PreviewModerationFeedRemovalRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(private readonly int $runId)
    {
        $this->onQueue('library-scans');
    }

    public function handle(FeedRemovalBackfillService $backfill): void
    {
        $run = ModerationFeedRemovalRun::query()->find($this->runId);

        if (! $run || ! in_array($run->status, [ModerationFeedRemovalRunStatus::PENDING, ModerationFeedRemovalRunStatus::PREVIEWING], true)) {
            return;
        }

        $backfill->previewRun($run);
    }
}
