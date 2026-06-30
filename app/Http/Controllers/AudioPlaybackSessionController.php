<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAudioPlaybackSessionRequest;
use App\Services\Audio\AudioPlaybackSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AudioPlaybackSessionController extends Controller
{
    public function show(Request $request, AudioPlaybackSessionService $sessions): JsonResponse
    {
        return response()->json($sessions->current((int) $request->user()->id));
    }

    public function claim(StoreAudioPlaybackSessionRequest $request, AudioPlaybackSessionService $sessions): JsonResponse
    {
        return response()->json($sessions->claim((int) $request->user()->id, $request->validated()));
    }

    public function heartbeat(StoreAudioPlaybackSessionRequest $request, AudioPlaybackSessionService $sessions): JsonResponse
    {
        $session = $sessions->heartbeat((int) $request->user()->id, $request->validated());

        if ($session === null) {
            return $this->leaseConflict();
        }

        return response()->json($session);
    }

    public function update(StoreAudioPlaybackSessionRequest $request, AudioPlaybackSessionService $sessions): JsonResponse
    {
        $session = $sessions->update((int) $request->user()->id, $request->validated());

        if ($session === null) {
            return $this->leaseConflict();
        }

        return response()->json($session);
    }

    public function release(StoreAudioPlaybackSessionRequest $request, AudioPlaybackSessionService $sessions): JsonResponse
    {
        return response()->json($sessions->release((int) $request->user()->id, $request->validated()));
    }

    private function leaseConflict(): JsonResponse
    {
        return response()->json([
            'message' => 'Playback ownership changed. Claim this device before controlling playback.',
        ], 409);
    }
}
