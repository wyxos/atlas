<?php

namespace App\Console\Commands;

use App\Jobs\ConvertAutoBlacklistsToAutoDislikes as ConvertAutoBlacklistsToAutoDislikesJob;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ConvertAutoBlacklistsToAutoDislikes extends Command
{
    protected $signature = 'atlas:convert-auto-blacklists-to-auto-dislikes
        {--user-id= : User ID that should own the dislike reactions}
        {--chunk=1000 : Number of matching files to process per queued job}
        {--queue=processing : Queue name to dispatch jobs to}
        {--start-id=0 : Resume scanning after this file ID}
        {--dry-run : Scan matching files without mutating records}';

    protected $description = 'Queue legacy null-reason blacklist conversion into auto-dislikes';

    public function handle(): int
    {
        if (! Schema::hasColumn('files', 'blacklist_reason')) {
            $this->error('files.blacklist_reason is missing. Run this conversion before dropping the legacy column.');

            return self::FAILURE;
        }

        $userId = $this->option('user-id');
        if (! is_numeric($userId) || ! User::query()->whereKey((int) $userId)->exists()) {
            $this->error('A valid --user-id is required.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $queue = trim((string) $this->option('queue'));
        $startId = max(0, (int) $this->option('start-id'));
        $dryRun = (bool) $this->option('dry-run');

        if ($queue === '') {
            $queue = 'processing';
        }

        ConvertAutoBlacklistsToAutoDislikesJob::dispatch((int) $userId, $startId, $chunk, $queue, $dryRun)
            ->onQueue($queue);

        $mode = $dryRun ? 'dry-run ' : '';
        $this->info(
            "Queued {$mode}legacy blacklist conversion from file id > {$startId} ".
            "with user-id={$userId}, chunk={$chunk}, queue={$queue}."
        );

        return self::SUCCESS;
    }
}
