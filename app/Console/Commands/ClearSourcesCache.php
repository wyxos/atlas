<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearSourcesCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:clear-sources {--group= : Clear cache for specific group (image or video)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear the cached sources list for photos/reels';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $group = $this->option('group');

        if ($group && ! in_array($group, ['image', 'video'], true)) {
            $this->error('Group must be either "image" or "video"');

            return 1;
        }

        if ($group) {
            $cacheKey = "files.sources.{$group}";
            Cache::forget($cacheKey);
            $this->info("Cleared cache for {$group} sources");

            return 0;
        }

        Cache::forget('files.sources.image');
        Cache::forget('files.sources.video');
        $this->info('Cleared cache for both image and video sources');

        return 0;
    }
}
