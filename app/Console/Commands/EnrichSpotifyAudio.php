<?php

namespace App\Console\Commands;

use App\Jobs\EnrichAudioJob;
use App\Models\File;
use Illuminate\Console\Command;

class EnrichSpotifyAudio extends Command
{
    protected $signature = 'atlas:enrich-spotify {--chunk=200} {--dry-run}';

    protected $description = 'Queue enrichment (artists, albums, covers) for Spotify-imported audio files';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dry = (bool) $this->option('dry-run');

        $q = File::query()
            ->where('mime_type', 'like', 'audio/%')
            ->where(function ($qq) {
                $qq->where('source', 'Spotify')->orWhere('mime_type', 'audio/spotify');
            });

        $total = (clone $q)->count();
        $this->info("Found {$total} Spotify audio files");

        $queued = 0;
        $q->orderBy('id')->chunk($chunk, function ($files) use (&$queued, $dry) {
            foreach ($files as $f) {
                if ($dry) {
                    $this->line('Would queue EnrichAudioJob for file ID '.$f->id);
                } else {
                    EnrichAudioJob::dispatch($f->id);
                }
                $queued++;
            }
        });

        $this->info($dry ? 'Dry-run complete' : ("Queued {$queued} enrich jobs"));

        return self::SUCCESS;
    }
}
