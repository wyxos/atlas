<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Playlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncReactionPlaylists extends Command
{
    protected $signature = 'reactions:sync-playlists {--chunk=1000 : Process files in chunks of this size}';

    protected $description = 'Ensure smart playlists exist and backfill membership for all audio files with detailed progress output';

    public function handle(): int
    {
        $this->info('== Reactions: Sync Smart Playlists ==');

        $names = ['All songs', 'Favorites', 'Liked', 'Disliked', 'Funny', 'Unrated'];
        $userId = DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            $this->warn('No users found; cannot create playlists.');

            return self::SUCCESS;
        }

        // Ensure smart playlists and log their parameters
        $this->line('> Ensuring playlists...');
        $playlists = collect($names)->mapWithKeys(function ($name) use ($userId) {
            $p = Playlist::firstOrCreate(['name' => $name], ['user_id' => $userId]);
            $params = match ($name) {
                'Favorites' => ['reaction' => 'love'],
                'Liked' => ['reaction' => 'like'],
                'Disliked' => ['reaction' => 'dislike'],
                'Funny' => ['reaction' => 'funny'],
                'Unrated' => ['reaction' => 'unrated'],
                default => null, // All songs
            };
            $p->is_smart = true;
            $p->smart_parameters = $params;
            $p->save();

            return [$name => $p];
        });

        foreach ($playlists as $name => $p) {
            $paramsStr = $p->smart_parameters ? json_encode($p->smart_parameters) : 'null';
            $this->line("  - {$name} [#{$p->id}] smart_parameters={$paramsStr}");
        }

        // Counters per playlist
        $counts = [];
        foreach ($playlists as $name => $p) {
            $counts[$name] = ['attach' => 0, 'detach' => 0];
        }

        $chunk = (int) $this->option('chunk');

        // Base query and totals
        $base = File::query()
            ->where('mime_type', 'like', 'audio/%')
            ->whereNull('blacklisted_at')
            ->where('not_found', false);

        $total = (clone $base)->count();
        $this->line("> Processing {$total} audio files (chunk {$chunk})...");
        $bar = $this->output->createProgressBar(max(1, $total));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%% | %elapsed:6s%');
        $bar->start();

        $processed = 0;
        $attached = 0;
        $detached = 0;
        $base->orderBy('id')->chunkById($chunk, function ($files) use (&$processed, &$attached, &$detached, $playlists, &$counts, $bar) {
            foreach ($files as $file) {
                // Determine reaction target
                $target = 'Unrated';
                if ($file->loved) {
                    $target = 'Favorites';
                } elseif ($file->liked) {
                    $target = 'Liked';
                } elseif ($file->disliked) {
                    $target = 'Disliked';
                } elseif ($file->funny) {
                    $target = 'Funny';
                }

                // Detach from smart reaction playlists (skip All songs here)
                foreach (['Favorites', 'Liked', 'Disliked', 'Funny', 'Unrated'] as $name) {
                    $p = $playlists[$name];
                    $detachedNow = $p->files()->detach($file->id);
                    $detached += $detachedNow;
                    $counts[$name]['detach'] += $detachedNow;
                }

                // Attach to target reaction playlist
                $playlists[$target]->files()->syncWithoutDetaching([$file->id]);
                $attached++;
                $counts[$target]['attach']++;

                // Ensure All songs contains it
                $playlists['All songs']->files()->syncWithoutDetaching([$file->id]);
                $counts['All songs']['attach']++;

                $processed++;
            }
            $bar->advance(count($files));
        }, 'id');

        $bar->finish();
        $this->newLine(2);

        $this->info("Summary: processed={$processed}, attached={$attached}, detached={$detached}");
        foreach ($counts as $name => $c) {
            $this->line(sprintf('  - %-10s attach=%d detach=%d', $name, $c['attach'], $c['detach']));
        }
        $this->info('Done.');

        return self::SUCCESS;
    }
}
