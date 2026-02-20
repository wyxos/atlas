<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExtensionBroadcastAuthRequest;
use App\Http\Requests\ExtensionRealtimeConfigRequest;
use App\Services\ExtensionRealtimeChannel;
use App\Services\ExtensionUserResolver;
use Illuminate\Http\JsonResponse;

class ExtensionRealtimeController extends Controller
{
    public function config(
        ExtensionRealtimeConfigRequest $request,
        ExtensionUserResolver $extensionUserResolver,
    ): JsonResponse {
        $baseUrl = rtrim((string) config('app.url', ''), '/');
        $scheme = (string) config('broadcasting.connections.reverb.options.scheme', 'https');
        $host = (string) config('broadcasting.connections.reverb.options.host', '');
        $port = (int) config('broadcasting.connections.reverb.options.port', $scheme === 'https' ? 443 : 80);
        $key = (string) config('broadcasting.connections.reverb.key', '');
        $user = $extensionUserResolver->resolve();

        if ($host === '' && $baseUrl !== '') {
            $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        }

        if ($host === '' || $key === '') {
            return $this->cors(response()->json([
                'message' => 'Realtime is not configured on this Atlas instance.',
            ], 503));
        }

        $channelName = ExtensionRealtimeChannel::channelName((int) $user->id);
        if (! $channelName) {
            return $this->cors(response()->json([
                'message' => 'Unable to resolve extension realtime channel.',
            ], 422));
        }

        return $this->cors(response()->json([
            'key' => $key,
            'wsHost' => $host,
            'wsPort' => $port,
            'wssPort' => $port,
            'forceTLS' => $scheme === 'https',
            'enabledTransports' => ['ws', 'wss'],
            'authEndpoint' => "{$baseUrl}/api/extension/broadcasting/auth",
            'channel' => "private-{$channelName}",
        ]));
    }

    public function auth(
        ExtensionBroadcastAuthRequest $request,
        ExtensionUserResolver $extensionUserResolver,
    ): JsonResponse {
        $validated = $request->validated();
        $key = (string) config('broadcasting.connections.reverb.key', '');
        $secret = (string) config('broadcasting.connections.reverb.secret', '');

        if ($key === '' || $secret === '') {
            return $this->cors(response()->json([
                'message' => 'Realtime is not configured on this Atlas instance.',
            ], 503));
        }

        $user = $extensionUserResolver->resolve();
        $expectedChannel = ExtensionRealtimeChannel::privateChannelName((int) $user->id);
        if (! $expectedChannel || $validated['channel_name'] !== $expectedChannel) {
            return $this->cors(response()->json([
                'message' => 'Channel is not authorized for this extension user.',
            ], 403));
        }

        $signaturePayload = "{$validated['socket_id']}:{$validated['channel_name']}";
        $signature = hash_hmac('sha256', $signaturePayload, $secret);

        return $this->cors(response()->json([
            'auth' => "{$key}:{$signature}",
        ]));
    }

    private function cors(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders([
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Atlas-Extension-Token, Authorization',
        ]);
    }
}
