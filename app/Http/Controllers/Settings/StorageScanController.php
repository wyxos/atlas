<?php

namespace App\Http\Controllers\Settings;

use App\Events\StorageProcessingProgress;
use App\Events\StorageScanProgress;
use App\Http\Controllers\Controller;
use App\Jobs\StorageScanJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StorageScanController extends Controller
{
    public function start(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Clear any previous cancel flag
        Cache::forget($this->cancelKey($user->id));

        // Prime status cache
        Cache::put($this->statusKey($user->id), [
            'total' => 0,
            'processed' => 0,
            'running' => true,
        ], now()->addMinutes(10));

        // Dispatch async scan
        StorageScanJob::dispatch($user->id);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        Cache::put($this->cancelKey($user->id), true, now()->addMinutes(10));

        Cache::forget($this->statusKey($user->id));
        Cache::forget('storage_processing:'.$user->id.':total');
        Cache::forget('storage_processing:'.$user->id.':done');
        Cache::forget('storage_processing:'.$user->id.':failed');

        event(new StorageScanProgress($user->id, 0, 0, true, true, 'Scan canceled'));
        event(new StorageProcessingProgress($user->id, 0, 0, 0));

        return response()->json(['ok' => true]);
    }

    protected function statusKey(int $userId): string
    {
        return 'storage_scan:'.$userId.':status';
    }

    protected function cancelKey(int $userId): string
    {
        return 'storage_scan:'.$userId.':cancel';
    }
}
