<?php

namespace App\Console\Commands;

use App\Services\MetricsService;
use Illuminate\Console\Command;

class SyncMetrics extends Command
{
    protected $signature = 'metrics:sync';

    protected $description = 'Recompute dashboard metrics and container counters';

    public function handle(): int
    {
        app(MetricsService::class)->syncAll();

        $this->info('Metrics synced.');

        return self::SUCCESS;
    }
}
