<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\FilePreviewOriginalHealthService;
use App\Support\FileMimeType;
use App\Support\FilePreviewGeneration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File as FileFacade;

class ReactedPreviewOriginalAuditCommand extends Command
{
    protected $signature = 'atlas:audit-reacted-preview-originals
        {--from=2026-05-26 : UTC start date, inclusive}
        {--to=2026-05-30 : UTC end date, inclusive}
        {--reaction=love,like,funny : Comma-separated positive reaction types}
        {--chunk=500 : Chunk size for scanning file IDs}
        {--limit=0 : Max number of affected files to report (0 = no limit)}
        {--output= : Exact report directory; defaults to storage/app/reports/reacted-preview-originals/<timestamp>}
        {--json : Output the aggregate report as JSON}';

    protected $description = 'Audit positive-reacted downloaded files whose originals cannot generate previews.';

    public function handle(FilePreviewOriginalHealthService $health): int
    {
        $window = $this->dateWindow();
        if ($window === null) {
            return self::FAILURE;
        }

        $reactionTypes = $this->reactionTypes();
        if ($reactionTypes === []) {
            $this->error('At least one reaction type is required.');

            return self::FAILURE;
        }

        $directory = $this->reportDirectory();
        FileFacade::ensureDirectoryExists($directory);

        $recordsPath = $directory.DIRECTORY_SEPARATOR.'affected-records.csv';
        $summaryPath = $directory.DIRECTORY_SEPARATOR.'summary.json';
        $handle = fopen($recordsPath, 'wb');
        if (! is_resource($handle)) {
            $this->error("Unable to write report CSV at [{$recordsPath}].");

            return self::FAILURE;
        }

        fputcsv($handle, $this->columns());

        $summary = [
            'from' => $window['start']->toIso8601String(),
            'to' => $window['end_inclusive']->toIso8601String(),
            'report_dir' => $directory,
            'files_scanned' => 0,
            'affected_files' => 0,
            'healthy_files_skipped' => 0,
            'unsupported_files_skipped' => 0,
            'reason_counts' => [],
            'source_counts' => [],
        ];
        $limit = max(0, (int) $this->option('limit'));
        $remaining = $limit;

        $this->baseQuery($window['start'], $window['end_exclusive'], $reactionTypes)
            ->chunkById(max(1, (int) $this->option('chunk')), function ($files) use (
                $health,
                $handle,
                $limit,
                &$remaining,
                &$summary,
            ): bool {
                foreach ($files as $file) {
                    if ($limit > 0 && $remaining <= 0) {
                        return false;
                    }

                    $summary['files_scanned']++;
                    $state = $health->inspect($file);
                    if (! $state['previewable']) {
                        $summary['unsupported_files_skipped']++;

                        continue;
                    }

                    if ($state['healthy']) {
                        $summary['healthy_files_skipped']++;

                        continue;
                    }

                    fputcsv($handle, $this->row($file, $state));
                    $summary['affected_files']++;
                    if ($limit > 0) {
                        $remaining--;
                    }

                    foreach ($state['reason_codes'] as $reasonCode) {
                        $summary['reason_counts'][$reasonCode] = (int) ($summary['reason_counts'][$reasonCode] ?? 0) + 1;
                    }

                    $source = (string) ($file->source ?? '');
                    $summary['source_counts'][$source] = (int) ($summary['source_counts'][$source] ?? 0) + 1;
                }

                return $limit === 0 || $remaining > 0;
            });

        fclose($handle);

        FileFacade::put($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Report written to {$directory}");
        $this->table(
            ['metric', 'value'],
            collect($summary)
                ->except(['reason_counts', 'source_counts'])
                ->map(fn (mixed $value, string $metric): array => [$metric, is_scalar($value) ? (string) $value : json_encode($value)])
                ->values()
                ->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $reactionTypes
     */
    private function baseQuery(CarbonImmutable $start, CarbonImmutable $endExclusive, array $reactionTypes): Builder
    {
        return File::query()
            ->select([
                'id',
                'source',
                'mime_type',
                'downloaded',
                'downloaded_at',
                'path',
                'preview_path',
                'poster_path',
                'size',
                'not_found',
            ])
            ->with(['reactions' => fn ($query) => $query
                ->select(['id', 'file_id', 'type', 'created_at'])
                ->whereIn('type', $reactionTypes)
                ->orderBy('created_at')])
            ->where('downloaded_at', '>=', $start)
            ->where('downloaded_at', '<', $endExclusive)
            ->whereHas('reactions', fn (Builder $query): Builder => $query->whereIn('type', $reactionTypes))
            ->where(function (Builder $query): void {
                $query->where('mime_type', 'like', 'image/%')
                    ->orWhere('mime_type', 'like', 'video/%')
                    ->orWhere('mime_type', 'application/mp4');
            })
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('mime_type', 'like', 'image/%')
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('preview_path')
                            ->orWhere('preview_path', ''));
                })->orWhere(function (Builder $query): void {
                    $query->where(function (Builder $query): void {
                        $query->where('mime_type', 'like', 'video/%')
                            ->orWhere('mime_type', 'application/mp4');
                    })->where(function (Builder $query): void {
                        $query->whereNull('preview_path')
                            ->orWhere('preview_path', '')
                            ->orWhereNull('poster_path')
                            ->orWhere('poster_path', '');
                    });
                });
            })
            ->orderBy('id');
    }

    /**
     * @param  array{
     *     reason_codes: list<string>,
     *     expected_size: int|null,
     *     actual_size: int|null,
     *     recommended_action: string
     * }  $health
     * @return list<string|int|null>
     */
    private function row(File $file, array $health): array
    {
        $reactionTypes = $file->reactions
            ->pluck('type')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->implode('|');
        $reactionDates = $file->reactions
            ->pluck('created_at')
            ->filter();

        return [
            (int) $file->id,
            (string) ($file->source ?? ''),
            FileMimeType::canonicalize($file->mime_type) ?? '',
            $file->downloaded_at?->toIso8601String(),
            $reactionTypes,
            $file->reactions->count(),
            $reactionDates->min()?->toIso8601String(),
            $reactionDates->max()?->toIso8601String(),
            $file->downloaded ? 'true' : 'false',
            $this->hasPath($file->path) ? 'true' : 'false',
            $this->hasPath($file->preview_path) ? 'true' : 'false',
            $this->hasPath($file->poster_path) ? 'true' : 'false',
            $health['expected_size'],
            $health['actual_size'],
            implode('|', $health['reason_codes']),
            (FilePreviewGeneration::state($file)['status'] ?? 'missing'),
            $file->not_found ? 'true' : 'false',
            $health['recommended_action'],
        ];
    }

    /**
     * @return list<string>
     */
    private function columns(): array
    {
        return [
            'file_id',
            'source',
            'mime_type',
            'downloaded_at',
            'reaction_types',
            'reaction_count',
            'first_reaction_at',
            'last_reaction_at',
            'downloaded',
            'path_present',
            'preview_path_present',
            'poster_path_present',
            'expected_size',
            'actual_size',
            'reason_codes',
            'preview_generation_status',
            'not_found',
            'recommended_action',
        ];
    }

    /**
     * @return array{start: CarbonImmutable, end_inclusive: CarbonImmutable, end_exclusive: CarbonImmutable}|null
     */
    private function dateWindow(): ?array
    {
        try {
            $start = CarbonImmutable::parse((string) $this->option('from'), 'UTC')->startOfDay();
            $endInclusive = CarbonImmutable::parse((string) $this->option('to'), 'UTC')->endOfDay();
        } catch (\Throwable) {
            $this->error('Invalid date window. Use YYYY-MM-DD dates.');

            return null;
        }

        if ($endInclusive->lt($start)) {
            $this->error('The --to date must be on or after --from.');

            return null;
        }

        return [
            'start' => $start,
            'end_inclusive' => $endInclusive,
            'end_exclusive' => $endInclusive->addMicrosecond(),
        ];
    }

    /**
     * @return list<string>
     */
    private function reactionTypes(): array
    {
        return collect(explode(',', (string) $this->option('reaction')))
            ->map(fn (string $reaction): string => trim($reaction))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function reportDirectory(): string
    {
        $output = $this->option('output');
        if (is_string($output) && trim($output) !== '') {
            return rtrim($output, '\\/');
        }

        return storage_path('app/reports/reacted-preview-originals/'.CarbonImmutable::now('UTC')->format('Ymd_His'));
    }

    private function hasPath(mixed $path): bool
    {
        return is_string($path) && trim($path) !== '';
    }
}
