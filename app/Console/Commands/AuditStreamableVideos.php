<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Services\LibraryScans\BrowserVideoSupport;
use App\Services\LibraryScans\MediaProbeService;
use App\Support\AtlasPathResolver;
use App\Support\AtlasStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AuditStreamableVideos extends Command
{
    protected $signature = 'atlas:audit-streamable-videos
        {--scope=imports : Storage namespace to scan: imports, downloads, or all}
        {--imports : Scan imported files}
        {--downloads : Scan downloaded files}
        {--all : Scan imported and downloaded files}
        {--chunk=500 : Chunk size for scanning file IDs}
        {--limit=0 : Max number of files to scan (0 = no limit)}
        {--dry-run : Read-only audit mode; this command never mutates files}
        {--json : Output the aggregate report as JSON}';

    protected $description = 'Audit managed videos that need generated streamable output.';

    public function handle(BrowserVideoSupport $browserVideoSupport, MediaProbeService $probe): int
    {
        $scope = $this->requestedScope();
        if ($scope === null) {
            return self::FAILURE;
        }

        $summary = $this->scan($browserVideoSupport, $probe, $scope);

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Mode: dry-run');
        $this->printSummary('Streamable Video Audit', $summary);

        return self::SUCCESS;
    }

    private function requestedScope(): ?string
    {
        $flagScopes = array_filter([
            'imports' => (bool) $this->option('imports'),
            'downloads' => (bool) $this->option('downloads'),
            'all' => (bool) $this->option('all'),
        ]);

        if (count($flagScopes) > 1) {
            $this->error('Choose only one of --imports, --downloads, or --all.');

            return null;
        }

        if ($flagScopes !== []) {
            return array_key_first($flagScopes);
        }

        $scope = strtolower((string) $this->option('scope'));
        if (! in_array($scope, ['imports', 'downloads', 'all'], true)) {
            $this->error('Invalid --scope. Use imports, downloads, or all.');

            return null;
        }

        return $scope;
    }

    /**
     * @return array<string, int|string>
     */
    private function scan(BrowserVideoSupport $browserVideoSupport, MediaProbeService $probe, string $scope): array
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $limit = max(0, (int) $this->option('limit'));
        $remaining = $limit;
        $summary = [
            'scope' => $scope,
            'video_files_scanned' => 0,
            'source_files_found' => 0,
            'missing_source_files' => 0,
            'unknown_size_files' => 0,
            'browser_supported_files' => 0,
            'browser_supported_bytes' => 0,
            'streamable_required_files' => 0,
            'streamable_required_bytes' => 0,
            'streamable_required_with_output_files' => 0,
            'streamable_required_with_output_bytes' => 0,
            'streamable_required_without_output_files' => 0,
            'streamable_required_without_output_bytes' => 0,
            'stale_streamable_metadata_files' => 0,
            'probe_failures' => 0,
        ];

        $this->baseQuery($scope)->chunkById($chunk, function ($files) use (
            $browserVideoSupport,
            $probe,
            $limit,
            &$remaining,
            &$summary,
        ): bool {
            foreach ($files as $file) {
                if ($limit > 0 && $remaining <= 0) {
                    return false;
                }

                if ($limit > 0) {
                    $remaining--;
                }

                $this->scanFile($file, $browserVideoSupport, $probe, $summary);
            }

            return $limit === 0 || $remaining > 0;
        });

        return $summary;
    }

    private function baseQuery(string $scope): Builder
    {
        return File::query()
            ->select(['id', 'path', 'mime_type', 'size'])
            ->with('metadata:id,file_id,payload')
            ->whereNotNull('path')
            ->where(function (Builder $query): void {
                $query->where('mime_type', 'like', 'video/%')
                    ->orWhere('mime_type', 'application/mp4');
            })
            ->when($scope !== 'all', fn (Builder $query): Builder => $query->where('path', 'like', "{$scope}/%"));
    }

    /**
     * @param  array<string, int|string>  $summary
     */
    private function scanFile(
        File $file,
        BrowserVideoSupport $browserVideoSupport,
        MediaProbeService $probe,
        array &$summary,
    ): void {
        $summary['video_files_scanned']++;
        $resolved = AtlasPathResolver::resolveExistingPath($file->path, [AtlasStorage::DISK]);

        if (! $resolved) {
            $summary['missing_source_files']++;

            return;
        }

        $summary['source_files_found']++;
        $sourceBytes = $this->sourceBytes($file, $resolved['size']);
        if ($sourceBytes === null) {
            $summary['unknown_size_files']++;
            $sourceBytes = 0;
        }

        try {
            $sourceProbe = $probe->probe($resolved['full_path']);
        } catch (Throwable) {
            $sourceProbe = [];
        }

        if ($sourceProbe === []) {
            $summary['probe_failures']++;
        }

        if (! $browserVideoSupport->shouldCreateStreamableVideo($file->mime_type, $sourceProbe)) {
            $summary['browser_supported_files']++;
            $summary['browser_supported_bytes'] += $sourceBytes;

            return;
        }

        $summary['streamable_required_files']++;
        $summary['streamable_required_bytes'] += $sourceBytes;

        $outputState = $this->streamableOutputState($file);
        if ($outputState === 'existing') {
            $summary['streamable_required_with_output_files']++;
            $summary['streamable_required_with_output_bytes'] += $sourceBytes;

            return;
        }

        if ($outputState === 'stale') {
            $summary['stale_streamable_metadata_files']++;
        }

        $summary['streamable_required_without_output_files']++;
        $summary['streamable_required_without_output_bytes'] += $sourceBytes;
    }

    private function sourceBytes(File $file, ?int $resolvedSize): ?int
    {
        if (is_int($resolvedSize) && $resolvedSize >= 0) {
            return $resolvedSize;
        }

        return is_numeric($file->size) && (int) $file->size >= 0 ? (int) $file->size : null;
    }

    private function streamableOutputState(File $file): string
    {
        $payload = $file->metadata?->payload;
        $streamablePath = data_get(is_array($payload) ? $payload : [], 'conversions.streamable_video');

        if (! is_string($streamablePath) || trim($streamablePath) === '') {
            return 'missing';
        }

        $streamablePath = $this->normalizeManagedStreamablePath($streamablePath);
        if ($streamablePath === null) {
            return 'stale';
        }

        return Storage::disk(AtlasStorage::DISK)->exists($streamablePath) ? 'existing' : 'stale';
    }

    private function normalizeManagedStreamablePath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/^[a-z]:/i', $path) === 1) {
            return null;
        }

        $namespace = explode('/', $path, 2)[0] ?? '';
        if (! in_array($namespace, AtlasStorage::namespaces(), true)) {
            return null;
        }

        if (! str_contains($path, '/conversions/') || ! str_ends_with(strtolower($path), '.mp4')) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<string, int|string>  $summary
     */
    private function printSummary(string $title, array $summary): void
    {
        $this->newLine();
        $this->info($title);
        $this->table(
            ['metric', 'value'],
            collect($summary)->map(fn (int|string $value, string $metric): array => [$metric, $value])->values()->all(),
        );
    }
}
