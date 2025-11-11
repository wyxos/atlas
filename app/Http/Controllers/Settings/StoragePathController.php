<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StoragePathController extends Controller
{
    public function edit(Request $request)
    {
        $hostname = gethostname() ?: php_uname('n');
        $defaultPath = env('ATLAS_STORAGE_PATH') ?: env('ATLAS_PATH') ?: storage_path('app/atlas');
        $effective = Setting::get('atlas.path', $defaultPath);
        $machineOverride = optional(Setting::query()->where('key', 'atlas.path')->where('machine', $hostname)->first())->value;

        // Hydrate scan + processing snapshot for initial render
        $user = $request->user();
        $scan = null;
        $processing = null;
        if ($user) {
            $scan = \Illuminate\Support\Facades\Cache::get('storage_scan:'.$user->id.':status');
            if ($scan && ($scan['processing_batch_id'] ?? null)) {
                $batch = \Illuminate\Support\Facades\Bus::findBatch($scan['processing_batch_id']);
                if ($batch) {
                    $processing = [
                        'id' => $batch->id,
                        'total' => $batch->totalJobs,
                        'processed' => $batch->processedJobs(),
                        'failed' => $batch->failedJobs,
                        'progress' => $batch->progress(),
                        'cancelled' => $batch->cancelled(),
                    ];
                } else {
                    $processing = [
                        'id' => (string) $scan['processing_batch_id'],
                        'total' => (int) ($scan['processing_total'] ?? 0),
                        'processed' => 0,
                        'failed' => 0,
                        'progress' => 0,
                        'cancelled' => false,
                    ];
                }
            }
        }

        // Spotify status
        $spotifyConnected = false;
        $spotifyScan = ['total' => 0, 'processed' => 0, 'running' => false];
        try {
            $spotifyConnected = \App\Models\SpotifyToken::where('user_id', optional($user)->id)->exists();
            if ($user) {
                $ss = \Illuminate\Support\Facades\Cache::get('spotify_scan:'.$user->id.':status');
                if (is_array($ss)) {
                    $spotifyScan = [
                        'total' => (int) ($ss['total'] ?? 0),
                        'processed' => (int) ($ss['processed'] ?? 0),
                        'running' => (bool) ($ss['running'] ?? false),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // ignore early schema
        }

        return Inertia::render('settings/Library', [
            'hostname' => $hostname,
            'effectivePath' => $effective,
            'machineOverride' => $machineOverride,
            'scanStatus' => $scan ?: [
                'total' => 0,
                'processed' => 0,
                'running' => false,
            ],
            'processing' => $processing,
            'spotifyConnected' => $spotifyConnected,
            'spotifyScanStatus' => $spotifyScan,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $path = $data['path'];

        // Save machine-specific override
        Setting::setForCurrentMachine('atlas.path', $path);

        // Apply immediately for this request
        config(['filesystems.disks.atlas.root' => $path]);

        // For PUT requests (axios), return JSON to avoid redirect loops
        // redirect()->back() can cause ERR_TOO_MANY_REDIRECTS with proxies/load balancers
        if ($request->isMethod('PUT') || $request->wantsJson() || $request->expectsJson()) {
            return response()->json(['status' => 'Storage path updated.']);
        }

        // Fallback for regular form submissions (shouldn't happen with current UI)
        return redirect()->route('library.edit')->with('status', 'Storage path updated.');
    }
}
