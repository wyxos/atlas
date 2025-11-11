<?php

namespace App\Jobs;

use App\Events\SpotifyScanProgress;
use App\Services\SpotifySavedTracks;
use App\Support\SpotifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SpotifyScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $userId) {}

    public function queue(): string
    {
        return 'spotify';
    }

    public function handle(): void
    {
        $client = new SpotifyClient;
        $token = $client->getAccessTokenForUser($this->userId);
        if (! $token) {
            // Not connected; broadcast done with 0
            event(new SpotifyScanProgress($this->userId, 0, 0, true, false, 'Connect Spotify first'));

            return;
        }

        $service = new SpotifySavedTracks;

        $limit = 50;
        $offset = 0;
        $processed = 0;
        $total = 0;

        do {
            if (Cache::get($this->cancelKey(), false)) {
                event(new SpotifyScanProgress($this->userId, $total, $processed, true, true, 'Scan canceled'));
                Cache::forget($this->statusKey());

                return;
            }

            $resp = Http::withToken($token)->get('https://api.spotify.com/v1/me/tracks', [
                'limit' => $limit,
                'offset' => $offset,
                'market' => 'from_token',
            ]);

            if ($resp->status() === 401) {
                if ($client->refreshForUser($this->userId)) {
                    $token = (string) (new SpotifyClient)->getAccessTokenForUser($this->userId);
                    $resp = Http::withToken($token)->get('https://api.spotify.com/v1/me/tracks', [
                        'limit' => $limit,
                        'offset' => $offset,
                        'market' => 'from_token',
                    ]);
                }
            }

            if ($resp->status() === 429) {
                $retryAfter = (int) $resp->header('Retry-After', '1');
                sleep(max(1, $retryAfter));

                continue; // retry same page
            }

            if (! $resp->ok()) {
                event(new SpotifyScanProgress($this->userId, $total, $processed, true, false, 'Spotify error: '.$resp->status()));

                return;
            }

            $json = (array) $resp->json();
            $total = (int) ($json['total'] ?? $total);

            $serviceParams = ['limit' => $limit, 'offset' => $offset];
            $filesPayload = $service->setParams($serviceParams ?? [])->transform($json)['files'] ?? [];

            if (! empty($filesPayload)) {
                $indexed = $service->persists($filesPayload);
                // Make files searchable (Scout/Typesense) and attach to playlists
                $i = 0;
                foreach ($indexed as $f) {
                    try {
                        $f->searchable();
                        $this->attachToPlaylists($f);
                        EnrichAudioJob::dispatch($f->id)->delay(now()->addMilliseconds(50 * ($i++ % 50)));
                    } catch (\Throwable $e) {
                    }
                }
                $processed += count($filesPayload);
            }

            Cache::put($this->statusKey(), [
                'total' => $total,
                'processed' => $processed,
                'running' => true,
            ], now()->addMinutes(10));

            event(new SpotifyScanProgress($this->userId, $total, $processed, false, false, null));

            $offset += $limit;
            // Gentle rate limit
            usleep(200_000);
        } while ($offset < $total);

        Cache::put($this->statusKey(), [
            'total' => $total,
            'processed' => $processed,
            'running' => false,
        ], now()->addMinutes(10));

        event(new SpotifyScanProgress($this->userId, $total, $processed, true, false, 'Scan complete'));
    }

    protected function statusKey(): string
    {
        return 'spotify_scan:'.$this->userId.':status';
    }

    protected function cancelKey(): string
    {
        return 'spotify_scan:'.$this->userId.':cancel';
    }

    protected function attachToPlaylists(\App\Models\File $file): void
    {
        // Only attach audio files that are valid
        $mime = (string) ($file->mime_type ?? '');
        if (! str_starts_with($mime, 'audio/') || $file->blacklisted_at !== null || $file->not_found) {
            return;
        }

        $userId = \Illuminate\Support\Facades\DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            return;
        }

        // All songs
        $all = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'All songs'],
            ['is_smart' => false, 'is_system' => true]
        );
        $all->files()->syncWithoutDetaching([$file->id]);

        // Unrated smart playlist
        $unrated = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Unrated'],
            ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['reaction' => 'unrated']]
        );
        $unrated->files()->syncWithoutDetaching([$file->id]);

        // Spotify-only smart playlist
        $spotifyPl = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Spotify'],
            ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['source' => 'spotify']]
        );
        $spotifyPl->files()->syncWithoutDetaching([$file->id]);
    }
}
