<?php

namespace App\Console\Commands;

use App\Services\ReactedPreviewOriginalRepairRunner;
use App\Services\ReactedPreviewOriginalRepairState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class RepairReactedPreviewOriginals extends Command
{
    private const string LOCK_KEY = 'atlas:repair-reacted-preview-originals';

    private const int MAX_PREVIEW_CAP = 25;

    protected $signature = 'atlas:repair-reacted-preview-originals
        {--report= : affected-records.csv path or its report directory}
        {--file-id=* : Only repair these file IDs when they are present in the report}
        {--limit=0 : Maximum report records to consider (0 = no limit)}
        {--max-downloads=2 : Maximum combined queued or active download workload}
        {--max-previews=5 : Maximum combined fresh processor or queued preview workload}
        {--stale-after-minutes=60 : Active processor task age that may be superseded}
        {--watch : Keep filling available slots until all report records are handled}
        {--poll-seconds=15 : Seconds between queue-capacity checks in watch mode}
        {--max-runtime=0 : Maximum watch runtime in seconds (0 = until dispatch is complete)}
        {--dry-run : Show candidate and workload counts without changing records or dispatching jobs}
        {--json : Output the final aggregate state as JSON}';

    protected $description = 'Repair audited reacted-file originals and previews with bounded download and processor queues.';

    public function handle(
        ReactedPreviewOriginalRepairRunner $runner,
        ReactedPreviewOriginalRepairState $state,
    ): int {
        $options = $this->validatedOptions();
        if ($options === null) {
            return self::FAILURE;
        }

        try {
            $state->initialize($options['report']);
            $candidates = $state->readCandidates(
                $options['report'],
                $options['file_ids'],
                $options['limit'],
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK_KEY, $this->lockSeconds($options));
        if (! $lock->get()) {
            $this->error('Another reacted preview-original repair command is already running.');

            return self::FAILURE;
        }

        try {
            $stats = $this->initialStats($candidates, $state->processedCount());
            $startedAt = microtime(true);
            $workloads = ['download' => 0, 'preview' => 0];
            $timedOut = false;
            $iteration = 0;

            do {
                if ($this->runtimeExceeded($options, $startedAt, $iteration)) {
                    $timedOut = true;

                    break;
                }

                $iteration++;
                $cutoff = CarbonImmutable::now('UTC')->subMinutes($options['stale_after_minutes']);
                $workloads = $options['dry_run']
                    ? $runner->workloads($cutoff)
                    : $runner->pump(
                        $candidates,
                        $options['max_downloads'],
                        $options['max_previews'],
                        $cutoff,
                        $state,
                        $stats,
                    );
                $state->persist();

                $pending = count($candidates['downloads']) + count($candidates['previews']);
                if ($options['watch']) {
                    $this->outputProgress($stats, $candidates, $workloads);
                }

                if ($options['dry_run'] || ! $options['watch'] || $pending === 0) {
                    break;
                }

                $sleepSeconds = $this->sleepSeconds($options, $startedAt);
                if ($sleepSeconds === null) {
                    $timedOut = true;

                    break;
                }
                sleep($sleepSeconds);
            } while (true);

            $summary = [
                ...$stats,
                'dry_run' => $options['dry_run'],
                'watch' => $options['watch'],
                'timed_out' => $timedOut,
                'iterations' => $iteration,
                'download_cap' => $options['max_downloads'],
                'preview_cap' => $options['max_previews'],
                'download_workload' => $workloads['download'],
                'preview_workload' => $workloads['preview'],
                'pending_download_candidates' => count($candidates['downloads']),
                'pending_preview_candidates' => count($candidates['previews']),
                'dispatch_complete' => $candidates['downloads'] === []
                    && $candidates['previews'] === []
                    && $stats['failed'] === 0,
            ];
            $this->outputSummary($summary, $options['json']);

            return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            try {
                $state->persist();
            } catch (Throwable) {
                $this->error('Repair stopped and its latest state could not be persisted.');

                return self::FAILURE;
            }

            $this->error('Repair stopped safely after an internal failure; prior progress was persisted.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{
     *     report: string,
     *     file_ids: list<int>,
     *     limit: int,
     *     max_downloads: int,
     *     max_previews: int,
     *     stale_after_minutes: int,
     *     watch: bool,
     *     poll_seconds: int,
     *     max_runtime: int,
     *     dry_run: bool,
     *     json: bool
     * }|null
     */
    private function validatedOptions(): ?array
    {
        $report = trim((string) $this->option('report'));
        if ($report === '') {
            $this->error('The --report option is required.');

            return null;
        }
        if (is_dir($report)) {
            $report = rtrim($report, '\\/').DIRECTORY_SEPARATOR.'affected-records.csv';
        }

        $realReport = realpath($report);
        if ($realReport === false || ! is_file($realReport) || ! is_readable($realReport)) {
            $this->error('The audit CSV could not be read.');

            return null;
        }

        $maxDownloads = (int) $this->option('max-downloads');
        $maxPreviews = (int) $this->option('max-previews');
        $engineDownloadCap = max(1, (int) config('downloads.max_transfers_total', 20));
        if ($maxDownloads < 0 || $maxDownloads > $engineDownloadCap) {
            $this->error("--max-downloads must be between 0 and {$engineDownloadCap}.");

            return null;
        }
        if ($maxPreviews < 0 || $maxPreviews > self::MAX_PREVIEW_CAP) {
            $this->error('--max-previews must be between 0 and '.self::MAX_PREVIEW_CAP.'.');

            return null;
        }
        if ($maxDownloads === 0 && $maxPreviews === 0 && ! $this->option('dry-run')) {
            $this->error('At least one repair cap must be greater than zero.');

            return null;
        }

        $pollSeconds = (int) $this->option('poll-seconds');
        if ($pollSeconds < 1 || $pollSeconds > 300) {
            $this->error('--poll-seconds must be between 1 and 300.');

            return null;
        }
        $maxRuntime = (int) $this->option('max-runtime');
        if ($maxRuntime < 0) {
            $this->error('--max-runtime must be zero or greater.');

            return null;
        }

        return [
            'report' => $realReport,
            'file_ids' => $this->selectedFileIds(),
            'limit' => max(0, (int) $this->option('limit')),
            'max_downloads' => $maxDownloads,
            'max_previews' => $maxPreviews,
            'stale_after_minutes' => max(1, (int) $this->option('stale-after-minutes')),
            'watch' => (bool) $this->option('watch'),
            'poll_seconds' => $pollSeconds,
            'max_runtime' => $maxRuntime,
            'dry_run' => (bool) $this->option('dry-run'),
            'json' => (bool) $this->option('json'),
        ];
    }

    /**
     * @return list<int>
     */
    private function selectedFileIds(): array
    {
        return collect((array) $this->option('file-id'))
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int|bool|array<int, int>>  $options
     */
    private function lockSeconds(array $options): int
    {
        if (! $options['watch']) {
            return 300;
        }

        return $options['max_runtime'] > 0 ? $options['max_runtime'] + 300 : 2_592_000;
    }

    /**
     * @param  array{report_records: int, downloads: list<int>, previews: list<int>}  $candidates
     * @return array<string, int>
     */
    private function initialStats(array $candidates, int $processedCount): array
    {
        return [
            'report_records' => $candidates['report_records'],
            'selected_records' => count($candidates['downloads']) + count($candidates['previews']),
            'download_candidates' => count($candidates['downloads']),
            'preview_candidates' => count($candidates['previews']),
            'redownloads_queued' => 0,
            'previews_queued' => 0,
            'unavailable' => 0,
            'resolved' => 0,
            'missing_records' => 0,
            'failed' => 0,
            'stale_tasks_superseded' => 0,
            'previously_processed' => $processedCount,
        ];
    }

    /**
     * @param  array<string, int|bool|array<int, int>>  $options
     */
    private function runtimeExceeded(array $options, float $startedAt, int $iteration): bool
    {
        return $iteration > 0
            && $options['max_runtime'] > 0
            && microtime(true) - $startedAt >= $options['max_runtime'];
    }

    /**
     * @param  array<string, int|bool|array<int, int>>  $options
     */
    private function sleepSeconds(array $options, float $startedAt): ?int
    {
        if ($options['max_runtime'] === 0) {
            return $options['poll_seconds'];
        }

        $remaining = $options['max_runtime'] - (microtime(true) - $startedAt);
        if ($remaining <= 0) {
            return null;
        }

        return min($options['poll_seconds'], max(1, (int) ceil($remaining)));
    }

    /**
     * @param  array<string, int>  $stats
     * @param  array{report_records: int, downloads: list<int>, previews: list<int>}  $candidates
     * @param  array{download: int, preview: int}  $workloads
     */
    private function outputProgress(array $stats, array $candidates, array $workloads): void
    {
        $this->line(sprintf(
            'repair progress: pending_downloads=%d pending_previews=%d download_workload=%d preview_workload=%d redownloads_queued=%d previews_queued=%d unavailable=%d failed=%d',
            count($candidates['downloads']),
            count($candidates['previews']),
            $workloads['download'],
            $workloads['preview'],
            $stats['redownloads_queued'],
            $stats['previews_queued'],
            $stats['unavailable'],
            $stats['failed'],
        ));
    }

    /**
     * @param  array<string, int|bool>  $summary
     */
    private function outputSummary(array $summary, bool $json): void
    {
        if ($json) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return;
        }

        $this->table(
            ['metric', 'value'],
            collect($summary)
                ->map(fn (int|bool $value, string $metric): array => [$metric, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value])
                ->values()
                ->all(),
        );
    }
}
