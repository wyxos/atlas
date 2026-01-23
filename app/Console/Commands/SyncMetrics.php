<?php

namespace App\Console\Commands;

use App\Services\MetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncMetrics extends Command
{
    protected $signature = 'metrics:sync';

    protected $description = 'Recompute dashboard metrics and container counters';

    public function handle(): int
    {
        $this->info('Syncing metrics...');
        $start = microtime(true);

        app(MetricsService::class)->syncAll();

        $elapsed = microtime(true) - $start;
        $metricCount = DB::table('metrics')->count();
        $this->info(sprintf('Metrics synced (%d keys) in %.2fs.', $metricCount, $elapsed));

        return self::SUCCESS;
    }
}
