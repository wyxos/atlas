<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\File;

class LinkProbeController extends Controller
{
    public function check(Request $request)
    {
        $data = $request->validate([
            'url' => ['required', 'url'],
        ]);

        $url = $data['url'];
        $status = null;

        try {
            $response = Http::timeout(10)->head($url);
            $status = $response->status();
        } catch (\Throwable $e) {
            // Some servers block HEAD; try a lightweight range GET
            try {
                $response = Http::withHeaders(['Range' => 'bytes=0-0'])->timeout(10)->get($url);
                $status = $response->status();
            } catch (\Throwable $e2) {
                // Network or upstream issue; report as 0 to indicate unknown
                $status = 0;
            }
        }

        // If 404, flag matching file records as not found
        if ($status === 404) {
            try {
                File::where('url', $url)->update(['not_found' => true]);
            } catch (\Throwable $e) {
                // Swallow DB errors to keep endpoint lightweight
            }
        }

        return response()->json([
            'ok' => $status >= 200 && $status < 400,
            'status' => $status,
        ]);
    }
}

