<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\SpotifyScanJob;
use App\Models\SpotifyToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class SpotifyController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $token = SpotifyToken::where('user_id', $user->id)->first();
        $isConnected = (bool) $token;

        $status = Cache::get($this->statusKey($user->id), ['total' => 0, 'processed' => 0, 'running' => false]);

        return Inertia::render('settings/Spotify', [
            'isConnected' => $isConnected,
            'scanStatus' => [
                'total' => (int) ($status['total'] ?? 0),
                'processed' => (int) ($status['processed'] ?? 0),
                'running' => (bool) ($status['running'] ?? false),
            ],
        ]);
    }

    public function start(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        Cache::forget($this->cancelKey($user->id));
        Cache::put($this->statusKey($user->id), [
            'total' => 0,
            'processed' => 0,
            'running' => true,
        ], now()->addMinutes(10));

        SpotifyScanJob::dispatch($user->id);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        Cache::put($this->cancelKey($user->id), true, now()->addMinutes(10));

        return response()->json(['ok' => true]);
    }

    protected function statusKey(int $userId): string
    {
        return 'spotify_scan:'.$userId.':status';
    }

    protected function cancelKey(int $userId): string
    {
        return 'spotify_scan:'.$userId.':cancel';
    }
}
