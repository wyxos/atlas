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

class ComposerUninstallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'composer';

    public function __construct(
        public int $userId,
        public string $packageName,
        public ?string $previousConstraint = null
    ) {}

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
                'uninstall',
                'running',
                "Uninstalling {$this->packageName}..."
            ));

            // Determine composer binary
            $composerBin = config('atlas.composer_bin', 'composer');

            // Run composer remove
            $process = new Process(
                [$composerBin, 'remove', $this->packageName, '--no-scripts', '--ignore-platform-reqs', '--no-interaction'],
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
                        'uninstall',
                        'running',
                        trim($buffer)
                    ));
                }
            });

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Composer uninstall failed: '.$process->getErrorOutput());
            }

            // Broadcast success
            event(new ComposerOperationProgress(
                $this->userId,
                $this->packageName,
                'uninstall',
                'completed',
                "Successfully uninstalled {$this->packageName}"
            ));

            Cache::forget($lockKey);
        } catch (\Throwable $e) {
            // Revert composer.plugins.json
            $this->revertComposerPluginsJson();

            // Broadcast failure
            event(new ComposerOperationProgress(
                $this->userId,
                $this->packageName,
                'uninstall',
                'failed',
                'Uninstallation failed: '.$e->getMessage()
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
            'uninstall',
            'failed',
            'Uninstallation failed'
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
            if (! $this->previousConstraint) {
                return;
            }

            $path = base_path('composer.plugins.json');
            if (! is_file($path)) {
                return;
            }

            $data = json_decode((string) file_get_contents($path), true) ?? [];
            $require = $data['require'] ?? [];

            // Re-add the package with its previous constraint
            $require[$this->packageName] = $this->previousConstraint;
            $data['require'] = $require;

            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                LOCK_EX
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
