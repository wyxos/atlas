<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Artist;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $fileId) {}

    public function handle(): void
    {
        $file = File::with(['artists', 'albums', 'metadata'])->find($this->fileId);
        if (! $file) {
            return;
        }

        $mime = (string) ($file->mime_type ?? '');
        if (! str_starts_with($mime, 'audio/')) {
            return;
        }

        $listing = (array) ($file->listing_metadata ?? []);
        $track = (array) ($listing['track'] ?? []);
        if (empty($track)) {
            return;
        }

        // Artists
        $artistNames = array_values(array_filter(array_map(function ($a) {
            return trim((string) ($a['name'] ?? ''));
        }, (array) ($track['artists'] ?? []))));

        foreach ($artistNames as $name) {
            if ($name === '') {
                continue;
            }
            $artist = Artist::firstOrCreate(['name' => $name]);
            $file->artists()->syncWithoutDetaching([$artist->id]);
        }

        // Album
        $albumArr = (array) ($track['album'] ?? []);
        $albumName = trim((string) ($albumArr['name'] ?? ''));
        $album = null;
        if ($albumName !== '') {
            $album = Album::firstOrCreate(['name' => $albumName]);
            $file->albums()->syncWithoutDetaching([$album->id]);
        }

        // Cover download (deferred)
        if ($album && $albumArr) {
            $images = is_array($albumArr['images'] ?? null) ? $albumArr['images'] : [];
            usort($images, function ($a, $b) {
                return (int) ($b['height'] ?? 0) <=> (int) ($a['height'] ?? 0);
            });
            $best = $images[0]['url'] ?? null;
            if ($best && ! $album->covers()->exists()) {
                DownloadCoverJob::dispatch('album', $album->id, (string) $best)->delay(now()->addSeconds(1));
            }
        }

        try {
            $file->searchable();
        } catch (\Throwable $e) {
        }
    }
}
