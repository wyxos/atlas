<?php

namespace App\Http\Controllers;

use App\Enums\ModerationFeedRemovalRunStatus;
use App\Http\Requests\StartModerationFeedRemovalRunRequest;
use App\Jobs\ApplyModerationFeedRemovalRun;
use App\Jobs\PreviewModerationFeedRemovalRun;
use App\Models\ModerationFeedRemovalRun;
use App\Services\Moderation\FeedRemovalBackfillPayload;
use App\Services\Moderation\FeedRemovalBackfillService;
use Illuminate\Http\JsonResponse;

class ModerationFeedRemovalRunController extends Controller
{
    public function index(FeedRemovalBackfillService $backfill): JsonResponse
    {
        $currentRulesHash = $backfill->currentRulesHash();
        $runs = ModerationFeedRemovalRun::query()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (ModerationFeedRemovalRun $run): array => FeedRemovalBackfillPayload::run($run, $currentRulesHash));

        return response()->json([
            'active_rule_count' => $backfill->currentRuleCount(),
            'items' => $runs,
        ]);
    }

    public function preview(
        StartModerationFeedRemovalRunRequest $request,
        FeedRemovalBackfillService $backfill,
    ): JsonResponse {
        $activeRun = ModerationFeedRemovalRun::query()
            ->whereIn('status', ModerationFeedRemovalRunStatus::active())
            ->latest()
            ->first();

        if ($activeRun) {
            return response()->json([
                'run' => FeedRemovalBackfillPayload::run($activeRun, $backfill->currentRulesHash()),
            ], 202);
        }

        $run = ModerationFeedRemovalRun::query()->create([
            'user_id' => $request->user()?->id,
            'status' => ModerationFeedRemovalRunStatus::PENDING,
            'phase' => 'queued',
            'chunk_size' => $backfill->normalizeChunkSize($request->integer('chunk_size', FeedRemovalBackfillService::DEFAULT_CHUNK_SIZE)),
        ]);

        PreviewModerationFeedRemovalRun::dispatch($run->id);

        return response()->json([
            'run' => FeedRemovalBackfillPayload::run($run->fresh(), $backfill->currentRulesHash()),
        ], 202);
    }

    public function apply(
        ModerationFeedRemovalRun $moderationFeedRemovalRun,
        FeedRemovalBackfillService $backfill,
    ): JsonResponse {
        if ($moderationFeedRemovalRun->matched_count <= 0) {
            return response()->json([
                'message' => 'This preview did not match any rows.',
                'run' => FeedRemovalBackfillPayload::run($moderationFeedRemovalRun, $backfill->currentRulesHash()),
            ], 422);
        }

        if (! $backfill->rulesMatchRun($moderationFeedRemovalRun)) {
            $moderationFeedRemovalRun->update([
                'status' => ModerationFeedRemovalRunStatus::STALE,
                'phase' => 'rules_changed',
                'finished_at' => now(),
                'error' => null,
            ]);

            return response()->json([
                'message' => 'Rules changed since this preview. Run preview again before applying.',
                'run' => FeedRemovalBackfillPayload::run($moderationFeedRemovalRun->fresh(), $backfill->currentRulesHash()),
            ], 409);
        }

        $updated = ModerationFeedRemovalRun::query()
            ->whereKey($moderationFeedRemovalRun->id)
            ->where('status', ModerationFeedRemovalRunStatus::PREVIEWED)
            ->update([
                'status' => ModerationFeedRemovalRunStatus::APPLYING,
                'phase' => 'queued',
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return response()->json([
                'message' => 'This preview is not ready to apply.',
                'run' => FeedRemovalBackfillPayload::run($moderationFeedRemovalRun->fresh(), $backfill->currentRulesHash()),
            ], 422);
        }

        ApplyModerationFeedRemovalRun::dispatch($moderationFeedRemovalRun->id);

        return response()->json([
            'run' => FeedRemovalBackfillPayload::run($moderationFeedRemovalRun->fresh(), $backfill->currentRulesHash()),
        ], 202);
    }
}
