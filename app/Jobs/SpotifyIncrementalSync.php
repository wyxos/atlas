<?php

namespace App\Jobs;

use App\Models\SpotifyToken;
use App\Services\SpotifySavedTracks;
use App\Support\SpotifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SpotifyIncrementalSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $tokens = SpotifyToken::query()->get();
        if ($tokens->isEmpty()) {
            return;
        }

        $client = new SpotifyClient;
        $service = new SpotifySavedTracks;

        foreach ($tokens as $row) {
            $userId = (int) $row->user_id;
            $token = $client->getAccessTokenForUser($userId);
            if (! $token) {
                continue;
            }

            $limit = 50;
            $offset = 0;
            $newMaxAddedAt = $row->last_synced_added_at ? $row->last_synced_added_at->toImmutable() : null;
            $stop = false;

            do {
                $resp = Http::withToken($token)->get('https://api.spotify.com/v1/me/tracks', [
                    'limit' => $limit,
                    'offset' => $offset,
                    'market' => 'from_token',
                ]);

                if ($resp->status() === 401) {
                    if ($client->refreshForUser($userId)) {
                        $token = (string) $client->getAccessTokenForUser($userId);
                        $resp = Http::withToken($token)->get('https://api.spotify.com/v1/me/tracks', [
                            'limit' => $limit,
                            'offset' => $offset,
                            'market' => 'from_token',
                        ]);
                    }
                }
                if (! $resp->ok()) {
                    break;
                }

                $json = (array) $resp->json();
                $items = (array) ($json['items'] ?? []);
                if (empty($items)) {
                    break;
                }

                // Stop page when we hit previously synced boundary
                if ($newMaxAddedAt === null) {
                    // On first-ever sync, set to the most recent item's added_at after this page
                    $firstAdded = (string) ($items[0]['added_at'] ?? '');
                    if ($firstAdded) {
                        $newMaxAddedAt = \Carbon\CarbonImmutable::parse($firstAdded);
                    }
                }

                // Filter only new items (added_at > last_synced_added_at)
                if ($row->last_synced_added_at) {
                    $items = array_filter($items, function ($it) use ($row) {
                        $addedAt = (string) ($it['added_at'] ?? '');
                        if ($addedAt === '') {
                            return false;
                        }

                        return \Carbon\Carbon::parse($addedAt)->greaterThan($row->last_synced_added_at);
                    });
                }

                if (! empty($items)) {
                    $mapped = $service->setParams(['limit' => $limit, 'offset' => $offset])->transform(['items' => array_values($items), 'total' => $json['total'] ?? 0])['files'] ?? [];
                    if (! empty($mapped)) {
                        $indexed = $service->persists($mapped);
                        $i = 0;
                        foreach ($indexed as $f) {
                            try {
                                $f->searchable();
                                $this->attachToPlaylists($f);
                                EnrichAudioJob::dispatch($f->id)->delay(now()->addMilliseconds(50 * ($i++ % 50)));
                            } catch (\Throwable $e) {
                            }
                        }
                    }
                } else {
                    // Nothing new on this page; we can stop.
                    $stop = true;
                }

                $offset += $limit;
                usleep(150_000);
            } while (! $stop);

            if ($newMaxAddedAt) {
                $row->last_synced_added_at = $newMaxAddedAt;
                $row->save();
            }
        }
    }

    protected function attachToPlaylists(\App\Models\File $file): void
    {
        $mime = (string) ($file->mime_type ?? '');
        if (! str_starts_with($mime, 'audio/') || $file->blacklisted_at !== null || $file->not_found) {
            return;
        }

        $userId = \Illuminate\Support\Facades\DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            return;
        }

        $all = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'All songs'],
            ['is_smart' => false, 'is_system' => true]
        );
        $all->files()->syncWithoutDetaching([$file->id]);

        $unrated = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Unrated'],
            ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['reaction' => 'unrated']]
        );
        $unrated->files()->syncWithoutDetaching([$file->id]);

        $spotifyPl = \App\Models\Playlist::firstOrCreate(
            ['user_id' => $userId, 'name' => 'Spotify'],
            ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['source' => 'spotify']]
        );
        $spotifyPl->files()->syncWithoutDetaching([$file->id]);
    }
}
