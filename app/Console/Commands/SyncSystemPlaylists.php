<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Playlists\SystemPlaylistCatalog;
use App\Services\Playlists\SystemPlaylistSyncService;
use Illuminate\Console\Command;

class SyncSystemPlaylists extends Command
{
    protected $signature = 'atlas:sync-system-playlists
        {--user= : Restrict sync to one user ID}
        {--dry-run : Report what would be synced without writing rows}';

    protected $description = 'Ensure system playlists exist for Atlas users';

    public function handle(
        SystemPlaylistCatalog $catalog,
        SystemPlaylistSyncService $sync,
    ): int {
        $userId = $this->option('user');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $definitions = $catalog->definitions($sync->audioSourceKeys());
            $users = $userId
                ? User::query()->whereKey((int) $userId)->count()
                : User::query()->count();

            $this->info(sprintf(
                'Would sync %d system playlist(s) for %d user(s).',
                count($definitions),
                $users,
            ));

            return self::SUCCESS;
        }

        if ($userId) {
            $user = User::query()->find((int) $userId);
            if (! $user) {
                $this->error('User not found.');

                return self::FAILURE;
            }

            $summary = ['users' => 1, ...$sync->syncForUser($user)];
        } else {
            $summary = $sync->syncAllUsers();
        }

        $this->info(sprintf(
            'Synced %d system playlist row(s) for %d user(s): %d created, %d updated, %d removed.',
            $summary['processed'],
            $summary['users'],
            $summary['created'],
            $summary['updated'],
            $summary['deleted'],
        ));

        return self::SUCCESS;
    }
}
