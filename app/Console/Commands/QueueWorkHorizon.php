<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class QueueWorkHorizon extends Command
{
    protected $signature = 'queue:work-horizon {--connection= : Queue connection to use} {--dry-run : Output queues without starting worker}';

    protected $description = 'Run queue worker using queues from Horizon config';

    public function handle(): int
    {
        $environment = app()->environment();
        $defaults = config('horizon.defaults', []);
        $overrides = config("horizon.environments.{$environment}", []);

        $queues = collect();
        $addQueues = function ($value) use (&$queues): void {
            if (is_string($value)) {
                $queues = $queues->merge(explode(',', $value));

                return;
            }

            if (is_array($value)) {
                $queues = $queues->merge($value);
            }
        };

        foreach ($defaults as $supervisor) {
            if (array_key_exists('queue', $supervisor)) {
                $addQueues($supervisor['queue']);
            }
        }

        foreach ($overrides as $supervisor) {
            if (array_key_exists('queue', $supervisor)) {
                $addQueues($supervisor['queue']);
            }
        }

        $queueList = $queues
            ->map(fn ($queue) => trim((string) $queue))
            ->filter()
            ->unique()
            ->values();

        if ($queueList->isEmpty()) {
            $this->error("No queues found in Horizon config for environment [{$environment}].");

            return self::FAILURE;
        }

        $queueArg = $queueList->implode(',');

        $this->info("Using queues: {$queueArg}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $arguments = ['--queue' => $queueArg];
        if ($this->option('connection')) {
            $arguments['--connection'] = $this->option('connection');
        }

        return $this->call('queue:work', $arguments);
    }
}
