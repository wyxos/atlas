<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File as FileFacade;
use RuntimeException;
use Throwable;

class ReactedPreviewOriginalRepairState
{
    /**
     * @var list<string>
     */
    private const array ORIGINAL_HEALTH_REASONS = [
        FilePreviewOriginalHealthService::MISSING_PATH,
        FilePreviewOriginalHealthService::MISSING_DISK_FILE,
        FilePreviewOriginalHealthService::EMPTY_DISK_FILE,
        FilePreviewOriginalHealthService::SIZE_MISMATCH,
        FilePreviewOriginalHealthService::UNREADABLE_IMAGE,
        FilePreviewOriginalHealthService::UNREADABLE_VIDEO,
        FilePreviewOriginalHealthService::UNSUPPORTED_MIME,
    ];

    /** @var array<int, true> */
    private array $processedFileIds = [];

    /** @var array<int, true> */
    private array $attemptedDownloadFileIds = [];

    /** @var array<int, true> */
    private array $attemptedPreviewFileIds = [];

    private string $statePath;

    private string $reportHash;

    private bool $dirty = false;

    public function initialize(string $report): void
    {
        $reportHash = hash_file('sha256', $report);
        if (! is_string($reportHash) || $reportHash === '') {
            throw new RuntimeException('The audit CSV fingerprint could not be calculated.');
        }

        $this->reportHash = $reportHash;
        $this->statePath = dirname($report).DIRECTORY_SEPARATOR.'repair-state.json';
        if (! FileFacade::exists($this->statePath)) {
            return;
        }

        try {
            $state = json_decode(FileFacade::get($this->statePath), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('The existing repair state could not be read safely.', previous: $exception);
        }

        if (! is_array($state) || ($state['version'] ?? null) !== 1) {
            throw new RuntimeException('The existing repair state has an unsupported format.');
        }
        if (! is_string($state['report_hash'] ?? null) || ! hash_equals($reportHash, $state['report_hash'])) {
            throw new RuntimeException('The existing repair state belongs to a different audit CSV.');
        }

        $this->processedFileIds = $this->idLookup($state['processed_file_ids'] ?? []);
        $this->attemptedDownloadFileIds = $this->idLookup($state['attempted_download_file_ids'] ?? []);
        $this->attemptedPreviewFileIds = $this->idLookup($state['attempted_preview_file_ids'] ?? []);
        $this->processedFileIds += $this->attemptedDownloadFileIds + $this->attemptedPreviewFileIds;
    }

    /**
     * @param  list<int>  $selectedFileIds
     * @return array{report_records: int, downloads: list<int>, previews: list<int>}
     */
    public function readCandidates(string $report, array $selectedFileIds, int $limit): array
    {
        $handle = fopen($report, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('The audit CSV could not be opened.');
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw new RuntimeException('The audit CSV is empty.');
            }

            $columns = array_flip($header);
            foreach (['file_id', 'reason_codes', 'recommended_action'] as $requiredColumn) {
                if (! array_key_exists($requiredColumn, $columns)) {
                    throw new RuntimeException("The audit CSV is missing the {$requiredColumn} column.");
                }
            }

            return $this->readRows($handle, $columns, $selectedFileIds, $limit);
        } finally {
            fclose($handle);
        }
    }

    public function recordProcessed(int $fileId, ?string $attempt = null): void
    {
        $this->processedFileIds[$fileId] = true;
        if ($attempt === 'download') {
            $this->attemptedDownloadFileIds[$fileId] = true;
        } elseif ($attempt === 'preview') {
            $this->attemptedPreviewFileIds[$fileId] = true;
        }

        $this->dirty = true;
    }

    public function persist(): void
    {
        if (! $this->dirty) {
            return;
        }

        $processed = array_keys($this->processedFileIds);
        $downloadAttempts = array_keys($this->attemptedDownloadFileIds);
        $previewAttempts = array_keys($this->attemptedPreviewFileIds);
        sort($processed, SORT_NUMERIC);
        sort($downloadAttempts, SORT_NUMERIC);
        sort($previewAttempts, SORT_NUMERIC);

        FileFacade::replace($this->statePath, json_encode([
            'version' => 1,
            'report_hash' => $this->reportHash,
            'processed_file_ids' => $processed,
            'attempted_download_file_ids' => $downloadAttempts,
            'attempted_preview_file_ids' => $previewAttempts,
            'updated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->dirty = false;
    }

    public function processedCount(): int
    {
        return count($this->processedFileIds);
    }

    /**
     * @param  resource  $handle
     * @param  array<string, int>  $columns
     * @param  list<int>  $selectedFileIds
     * @return array{report_records: int, downloads: list<int>, previews: list<int>}
     */
    private function readRows($handle, array $columns, array $selectedFileIds, int $limit): array
    {
        $selectedLookup = array_fill_keys($selectedFileIds, true);
        $seen = [];
        $downloads = [];
        $previews = [];
        $reportRecords = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $reportRecords++;
            $fileId = (int) ($row[$columns['file_id']] ?? 0);
            if ($fileId <= 0 || isset($seen[$fileId]) || isset($this->processedFileIds[$fileId])) {
                continue;
            }
            if ($selectedLookup !== [] && ! isset($selectedLookup[$fileId])) {
                continue;
            }
            if ($limit > 0 && count($downloads) + count($previews) >= $limit) {
                continue;
            }

            $seen[$fileId] = true;
            $reasonCodes = array_values(array_filter(explode('|', (string) ($row[$columns['reason_codes']] ?? ''))));
            $recommendedAction = (string) ($row[$columns['recommended_action']] ?? '');
            $hasOriginalHealthReason = array_intersect($reasonCodes, self::ORIGINAL_HEALTH_REASONS) !== [];

            if ($hasOriginalHealthReason || $recommendedAction !== 'retry_preview_generation') {
                $downloads[] = $fileId;
            } else {
                $previews[] = $fileId;
            }
        }

        return [
            'report_records' => $reportRecords,
            'downloads' => $downloads,
            'previews' => $previews,
        ];
    }

    /**
     * @return array<int, true>
     */
    private function idLookup(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return collect($ids)
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->mapWithKeys(fn (mixed $id): array => [(int) $id => true])
            ->all();
    }
}
