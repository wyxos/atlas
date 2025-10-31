<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Playlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncNotFoundPlaylist extends Command
{
    protected $signature = 'playlists:sync-not-found {--chunk=1000 : Process files in chunks}';

    protected $description = 'Ensure a "Not Found" smart playlist exists per user and attach all audio files flagged not_found; updates search index';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $this->info("== Playlists: Sync Not Found == (chunk {$chunk})");

        $userIds = DB::table('users')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (empty($userIds)) {
            $this->warn('No users found.');

            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            $playlist = Playlist::firstOrCreate(
                ['user_id' => $userId, 'name' => 'Not Found'],
                ['is_smart' => true, 'is_system' => true, 'smart_parameters' => ['status' => 'not_found']]
            );
            $this->line("> User #{$userId} => Playlist #{$playlist->id}");

            $base = File::query()
                ->where('mime_type', 'like', 'audio/%')
                ->where('not_found', true)
                ->whereNull('blacklisted_at')
                ->orderBy('id');

            $total = (clone $base)->count();
            $bar = $this->output->createProgressBar(max(1, $total));
            $bar->start();

            $base->chunkById($chunk, function ($files) use ($playlist, $bar) {
                $ids = $files->pluck('id')->map(fn ($v) => (int) $v)->values()->all();
                if (! empty($ids)) {
                    $playlist->files()->syncWithoutDetaching($ids);
                    // Refresh index for these files with playlists relation
                    try {
                        File::whereIn('id', $ids)->with('playlists')->searchable();
                    } catch (\Throwable $e) {
                        // ignore indexing errors
                    }
                }
                $bar->advance(count($ids));
            }, 'id');

            $bar->finish();
            $this->newLine();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
