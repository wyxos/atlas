<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Playlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearAudioNotFoundFlags extends Command
{
    protected $signature = 'audio:clear-not-found {--chunk=1000 : Process files in chunks of this size}';

    protected $description = 'Set not_found=false for all audio files and remove them from any "Not Found" playlists; updates Typesense index.';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $this->info("== Audio: Clear not_found flags == (chunk {$chunk})");

        // Collect all Not Found playlists across users
        $notFoundIds = Playlist::query()
            ->where('name', 'Not Found')
            ->pluck('id')
            ->all();

        if (! empty($notFoundIds)) {
            $deleted = DB::table('file_playlist')->whereIn('playlist_id', $notFoundIds)->delete();
            $this->line("> Detached {$deleted} pivot rows from Not Found playlists");
        } else {
            $this->line('> No Not Found playlists found');
        }

        // Find all audio files currently flagged not_found
        $ids = File::query()
            ->where('mime_type', 'like', 'audio/%')
            ->where('not_found', true)
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (empty($ids)) {
            $this->info('No audio files with not_found=true. Done.');

            return self::SUCCESS;
        }

        $this->line('> Updating not_found=false on '.count($ids).' files');
        // Bulk update in chunks
        foreach (array_chunk($ids, max(1, $chunk)) as $chunkIds) {
            DB::table('files')->whereIn('id', $chunkIds)->update(['not_found' => false, 'updated_at' => now()]);
            try {
                File::whereIn('id', $chunkIds)->with('playlists')->searchable();
            } catch (\Throwable $e) {
                // ignore indexing errors
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
