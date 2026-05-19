<?php

namespace App\Services;

use App\Models\File;
use App\Models\FileSource;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FileSourceRegistry
{
    /**
     * @return list<string>
     */
    public function activeSources(): array
    {
        return FileSource::query()
            ->where('active_file_count', '>', 0)
            ->orderBy('source')
            ->pluck('source')
            ->filter(fn (mixed $source): bool => is_string($source) && trim($source) !== '')
            ->values()
            ->all();
    }

    public function recordCreated(File $file): void
    {
        $source = $this->normalizeSource($file->source);
        if ($source === null) {
            return;
        }

        $this->adjustSource(
            $source,
            totalDelta: 1,
            activeDelta: $this->isActiveFile($file) ? 1 : 0,
            seenAt: $this->seenAt($file),
        );
    }

    public function recordUpdated(File $file): void
    {
        if (! $file->wasChanged(['source', 'not_found', 'previewed_count'])) {
            return;
        }

        $previousSource = $this->normalizeSource($file->getOriginal('source'));
        $nextSource = $this->normalizeSource($file->source);
        $previousActive = $this->isActiveFromValues(
            $file->getOriginal('not_found'),
            $file->getOriginal('previewed_count'),
        );
        $nextActive = $this->isActiveFile($file);

        if ($previousSource !== null && $previousSource !== $nextSource) {
            $this->adjustSource(
                $previousSource,
                totalDelta: -1,
                activeDelta: $previousActive ? -1 : 0,
                seenAt: null,
            );
        }

        if ($nextSource !== null && $previousSource !== $nextSource) {
            $this->adjustSource(
                $nextSource,
                totalDelta: 1,
                activeDelta: $nextActive ? 1 : 0,
                seenAt: $this->seenAt($file),
            );

            return;
        }

        if ($nextSource !== null && $previousActive !== $nextActive) {
            $this->adjustSource(
                $nextSource,
                totalDelta: 0,
                activeDelta: $nextActive ? 1 : -1,
                seenAt: $this->seenAt($file),
            );
        }
    }

    public function recordDeleted(File $file): void
    {
        $source = $this->normalizeSource($file->source);
        if ($source === null) {
            return;
        }

        $this->adjustSource(
            $source,
            totalDelta: -1,
            activeDelta: $this->isActiveFile($file) ? -1 : 0,
            seenAt: null,
        );
    }

    public function syncFromFiles(): int
    {
        $rows = File::query()
            ->select('source')
            ->selectRaw('COUNT(*) as total_file_count')
            ->selectRaw(
                'SUM(CASE WHEN not_found = 0 AND previewed_count < ? THEN 1 ELSE 0 END) as active_file_count',
                [FilePreviewService::FEED_REMOVED_PREVIEW_COUNT],
            )
            ->selectRaw('MAX(updated_at) as last_seen_at')
            ->whereNotNull('source')
            ->where('source', '<>', '')
            ->groupBy('source')
            ->get();

        $sources = [];
        foreach ($rows as $row) {
            $source = $this->normalizeSource($row->source);
            if ($source === null) {
                continue;
            }

            $lastSeenAt = $row->last_seen_at ? Carbon::parse($row->last_seen_at) : null;
            if (! isset($sources[$source])) {
                $sources[$source] = [
                    'source' => $source,
                    'total_file_count' => 0,
                    'active_file_count' => 0,
                    'last_seen_at' => $lastSeenAt,
                ];
            }

            $sources[$source]['total_file_count'] += (int) $row->total_file_count;
            $sources[$source]['active_file_count'] += (int) $row->active_file_count;

            if ($lastSeenAt !== null && (
                $sources[$source]['last_seen_at'] === null
                || $lastSeenAt->greaterThan($sources[$source]['last_seen_at'])
            )) {
                $sources[$source]['last_seen_at'] = $lastSeenAt;
            }
        }

        DB::transaction(function () use ($sources): void {
            FileSource::query()->delete();

            foreach ($sources as $source) {
                FileSource::query()->create($source);
            }
        });

        return count($sources);
    }

    private function adjustSource(string $source, int $totalDelta, int $activeDelta, ?CarbonInterface $seenAt): void
    {
        DB::transaction(function () use ($source, $totalDelta, $activeDelta, $seenAt): void {
            $record = FileSource::query()
                ->where('source', $source)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                if ($totalDelta <= 0 && $activeDelta <= 0) {
                    return;
                }

                $record = new FileSource([
                    'source' => $source,
                    'total_file_count' => 0,
                    'active_file_count' => 0,
                ]);
            }

            $record->total_file_count = max(0, (int) $record->total_file_count + $totalDelta);
            $record->active_file_count = max(0, (int) $record->active_file_count + $activeDelta);

            if ($seenAt !== null && (
                $record->last_seen_at === null
                || $seenAt->greaterThan($record->last_seen_at)
            )) {
                $record->last_seen_at = $seenAt;
            }

            $record->save();
        });
    }

    private function isActiveFile(File $file): bool
    {
        return $this->isActiveFromValues($file->not_found, $file->previewed_count);
    }

    private function isActiveFromValues(mixed $notFound, mixed $previewedCount): bool
    {
        return ! (bool) $notFound
            && (int) $previewedCount < FilePreviewService::FEED_REMOVED_PREVIEW_COUNT;
    }

    private function normalizeSource(mixed $source): ?string
    {
        if (! is_string($source)) {
            return null;
        }

        $source = trim($source);

        return $source !== '' ? $source : null;
    }

    private function seenAt(File $file): CarbonInterface
    {
        return $file->updated_at ?? now();
    }
}
