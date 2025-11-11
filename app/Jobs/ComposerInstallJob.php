<?php

namespace App\Jobs;

use App\Events\ComposerOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class ComposerInstallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId,
        public string $packageName
    ) {}

    public function queue(): string
    {
        return 'composer';
    }

    public function handle(): void
    {
        $lockKey = $this->lockKey();

        try {
            // Set cache lock
            Cache::put($lockKey, true, now()->addMinutes(30));

            // Broadcast start
            event(new ComposerOperationProgress(
                $this->userId,
                $this->packageName,
                'install',
                'running',
                "Installing {$this->packageName}..."
            ));

            // Determine composer binary
            $composerBin = config('atlas.composer_bin', 'composer');

            // Run composer update
            $process = new Process(
                [$composerBin, 'update', $this->packageName, '--no-scripts', '--ignore-platform-reqs', '--no-interaction'],
                base_path(),
                null,
                null,
                600 // 10 minute timeout
            );

            $process->run(function ($type, $buffer) {
                // Optionally broadcast output chunks
                if (trim($buffer) !== '') {
                    event(new ComposerOperationProgress(
                        $this->userId,
                        $this->packageName,
                        'install',
                        'running',
                        trim($buffer)
                    ));
                }
            });

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Composer install failed: '.$process->getErrorOutput());
            }

            // Broadcast success
            event(new ComposerOperationProgress(
                $this->userId,
                $this->packageName,
                'install',
                'completed',
                "Successfully installed {$this->packageName}"
            ));

            Cache::forget($lockKey);
        } catch (\Throwable $e) {
            // Revert composer.plugins.json
            $this->revertComposerPluginsJson();

            // Broadcast failure
            event(new ComposerOperationProgress(
                $this->userId,
                $this->packageName,
                'install',
                'failed',
                'Installation failed: '.$e->getMessage()
            ));

            Cache::forget($lockKey);
            report($e);

            return;
        }
    }

    public function failed(\Throwable $e): void
    {
        $this->revertComposerPluginsJson();

        event(new ComposerOperationProgress(
            $this->userId,
            $this->packageName,
            'install',
            'failed',
            'Installation failed'
        ));

        Cache::forget($this->lockKey());
    }

    protected function lockKey(): string
    {
        return 'composer_op:lock:'.$this->userId;
    }

    protected function revertComposerPluginsJson(): void
    {
        try {
            $path = base_path('composer.plugins.json');
            if (! is_file($path)) {
                return;
            }

            $data = json_decode((string) file_get_contents($path), true) ?? [];
            $require = $data['require'] ?? [];

            if (isset($require[$this->packageName])) {
                unset($require[$this->packageName]);
                $data['require'] = $require;

                file_put_contents(
                    $path,
                    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                    LOCK_EX
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
