<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CivitaiTestController extends Controller
{
    public function testQuery(): array
    {
        // Test the NextJS data endpoint with browser-like headers
        $url = 'https://civitai.com/_next/data/tTKEvY61haTaV1-Is5QjD/en/posts/20493073.json';

        $queryParams = [
            'postId' => '20493073'
        ];

        // Browser-like headers from network capture
        $headers = [
            'Accept' => '*/*',
            'Accept-Encoding' => 'gzip, deflate, br, zstd',
            'Accept-Language' => 'en-US,en;q=0.6',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Priority' => 'u=1, i',
            'Purpose' => 'prefetch',
            'Referer' => 'https://civitai.com/posts',
            'Sec-Ch-Ua' => '"Not)A;Brand";v="8", "Chromium";v="138", "Brave";v="138"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Gpc' => '1',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'X-Middleware-Prefetch' => '1',
            'X-Nextjs-Data' => '1',
            // Note: Not including cookie header as it contains sensitive auth tokens
        ];

        try {
            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->get($url, $queryParams);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'url' => $url . '?' . http_build_query($queryParams),
                    'data' => $response->json(),
                    'response_headers' => $response->headers(),
                    'request_headers' => $headers
                ];
            } else {
                Log::error('Civitai request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'headers' => $response->headers()
                ]);
                return [
                    'success' => false,
                    'status' => $response->status(),
                    'error' => $response->body(),
                    'response_headers' => $response->headers(),
                    'request_headers' => $headers
                ];
            }
        } catch (Exception $e) {
            Log::error('Civitai request error', ['message' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_headers' => $headers
            ];
        }
    }
}
