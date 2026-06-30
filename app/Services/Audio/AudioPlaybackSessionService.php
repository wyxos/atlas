<?php

namespace App\Services\Audio;

use App\Events\AudioPlaybackSessionUpdated;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class AudioPlaybackSessionService
{
    private const TTL_SECONDS = 20;

    /**
     * @return array<string, mixed>
     */
    public function current(int $userId): array
    {
        $session = Cache::get($this->cacheKey($userId));

        return is_array($session) ? $session : $this->emptySession();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function claim(int $userId, array $payload): array
    {
        $current = $this->current($userId);
        $session = $this->sessionFromPayload($current, $payload, [
            'lease_token' => (string) Str::uuid(),
            'owner_instance_id' => (string) $payload['instance_id'],
            'owner_label' => $this->nullableString($payload['owner_label'] ?? null),
        ]);

        return $this->storeAndBroadcast($userId, $session);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function heartbeat(int $userId, array $payload): ?array
    {
        $current = $this->current($userId);
        if (! $this->ownsSession($current, $payload)) {
            return null;
        }

        $session = $this->sessionFromPayload($current, $payload);

        return $this->storeAndBroadcast($userId, $session);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function update(int $userId, array $payload): ?array
    {
        $current = $this->current($userId);
        if (! $this->ownsSession($current, $payload)) {
            return null;
        }

        $session = $this->sessionFromPayload($current, $payload);

        return $this->storeAndBroadcast($userId, $session);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function release(int $userId, array $payload): array
    {
        $current = $this->current($userId);
        if (! $this->ownsSession($current, $payload)) {
            return $current;
        }

        $session = [
            ...$current,
            'version' => ((int) $current['version']) + 1,
            'lease_token' => null,
            'owner_instance_id' => null,
            'owner_label' => null,
            'state' => 'paused',
            'position_seconds' => $this->seconds($payload['position_seconds'] ?? $current['position_seconds']),
            'server_recorded_at_ms' => $this->nowMilliseconds(),
        ];

        return $this->storeAndBroadcast($userId, $session);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySession(): array
    {
        return [
            'version' => 0,
            'lease_token' => null,
            'owner_instance_id' => null,
            'owner_label' => null,
            'state' => 'idle',
            'source' => null,
            'current_track' => null,
            'queue_label' => null,
            'position_seconds' => 0.0,
            'duration_seconds' => null,
            'spotify_device_id' => null,
            'server_recorded_at_ms' => $this->nowMilliseconds(),
        ];
    }

    private function cacheKey(int $userId): string
    {
        return 'audio-playback-session:user:'.$userId;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $ownership
     * @return array<string, mixed>
     */
    private function sessionFromPayload(array $current, array $payload, array $ownership = []): array
    {
        return [
            ...$current,
            ...$ownership,
            'version' => ((int) $current['version']) + 1,
            'state' => $this->nullableString($payload['state'] ?? null) ?? (string) $current['state'],
            'source' => $this->nullableString($payload['source'] ?? null) ?? $current['source'],
            'current_track' => Arr::exists($payload, 'current_track') ? $payload['current_track'] : $current['current_track'],
            'queue_label' => Arr::exists($payload, 'queue_label') ? $this->nullableString($payload['queue_label']) : $current['queue_label'],
            'position_seconds' => $this->seconds($payload['position_seconds'] ?? $current['position_seconds']),
            'duration_seconds' => Arr::exists($payload, 'duration_seconds') ? $this->nullableSeconds($payload['duration_seconds']) : $current['duration_seconds'],
            'spotify_device_id' => Arr::exists($payload, 'spotify_device_id') ? $this->nullableString($payload['spotify_device_id']) : $current['spotify_device_id'],
            'server_recorded_at_ms' => $this->nowMilliseconds(),
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     * @param  array<string, mixed>  $payload
     */
    private function ownsSession(array $session, array $payload): bool
    {
        return $session['owner_instance_id'] !== null
            && hash_equals((string) $session['owner_instance_id'], (string) ($payload['instance_id'] ?? ''))
            && $session['lease_token'] !== null
            && hash_equals((string) $session['lease_token'], (string) ($payload['lease_token'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function storeAndBroadcast(int $userId, array $session): array
    {
        Cache::put($this->cacheKey($userId), $session, now()->addSeconds(self::TTL_SECONDS));

        Event::dispatch(new AudioPlaybackSessionUpdated($userId, $session));

        return $session;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function seconds(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
    }

    private function nullableSeconds(mixed $value): ?float
    {
        return $value === null ? null : $this->seconds($value);
    }

    private function nowMilliseconds(): int
    {
        return now()->getTimestamp() * 1000;
    }
}
