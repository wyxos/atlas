<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAudioPlaybackEventRequest;
use App\Services\Audio\AudioPlaybackStatsService;
use Illuminate\Http\JsonResponse;

class AudioPlaybackEventController extends Controller
{
    public function store(StoreAudioPlaybackEventRequest $request, AudioPlaybackStatsService $stats): JsonResponse
    {
        $validated = $request->validated();
        $stat = $stats->record(
            (int) $request->user()->id,
            (int) $validated['file_id'],
            (string) $validated['event'],
        );

        return response()->json([
            'file_id' => (int) $stat->file_id,
            'last_played_at' => $stat->last_played_at?->toIso8601String(),
            'last_skipped_at' => $stat->last_skipped_at?->toIso8601String(),
            'play_count' => (int) $stat->play_count,
            'skip_count' => (int) $stat->skip_count,
        ]);
    }
}
