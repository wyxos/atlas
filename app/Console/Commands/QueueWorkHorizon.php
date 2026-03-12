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
        $supervisors = collect($defaults);

        foreach ($overrides as $name => $override) {
            $base = $supervisors->get($name, []);

            $supervisors->put(
                $name,
                array_replace(is_array($base) ? $base : [], is_array($override) ? $override : []),
            );
        }

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

        $maxMemory = (int) $supervisors->pluck('memory')->filter()->max();
        $maxTimeout = (int) $supervisors->pluck('timeout')->filter()->max();
        $maxTries = (int) $supervisors->pluck('tries')->filter()->max();

        $previewMemoryLimit = (string) config('downloads.preview_php_memory_limit', '');
        if ($previewMemoryLimit !== '') {
            @ini_set('memory_limit', $previewMemoryLimit);
        }

        $arguments = ['--queue' => $queueArg];
        if ($this->option('connection')) {
            $arguments['--connection'] = $this->option('connection');
        }
        if ($maxMemory > 0) {
            $arguments['--memory'] = $maxMemory;
        }
        if ($maxTimeout > 0) {
            $arguments['--timeout'] = $maxTimeout;
        }
        if ($maxTries > 0) {
            $arguments['--tries'] = $maxTries;
        }

        return $this->call('queue:work', $arguments);
    }
}
