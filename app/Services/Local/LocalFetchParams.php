<?php

namespace App\Services\Local;

use DateTimeImmutable;
use DateTimeZone;

class LocalFetchParams
{
    /**
     * @return array{
     *   params: array<string, mixed>,
     *   page: int,
     *   limit: int,
     *   source: array<int, string>,
     *   downloaded: string,
     *   imported: string,
     *   notFound: string,
     *   blacklisted: string,
     *   sort: string,
     *   fileTypes: array<int, string>,
     *   seed: ?int,
     *   maxPreviewed: ?int,
     *   minPreviewed: ?int,
     *   createdFrom: ?int,
     *   createdTo: ?int,
     *   reactionMode: string,
     *   autoBlacklisted: string,
     *   reactionTypes: ?array<int, string>,
     *   includeTotal: bool,
     *   allTypes: array<int, string>,
     *   shouldReturnEmpty: bool
     * }
     */
    public static function normalize(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $limit = (int) ($params['limit'] ?? 20);
        $source = self::normalizeSources($params['source'] ?? 'all');
        $params['source'] = count($source) === 1 ? $source[0] : $source;
        $downloaded = is_string($params['downloaded'] ?? null) ? (string) $params['downloaded'] : 'any';
        $imported = is_string($params['imported'] ?? null) ? (string) $params['imported'] : 'any';
        $notFound = is_string($params['not_found'] ?? null) ? (string) $params['not_found'] : 'no';
        $blacklisted = is_string($params['blacklisted'] ?? null) ? (string) $params['blacklisted'] : 'any';
        $sort = is_string($params['sort'] ?? null) ? (string) $params['sort'] : 'stored_at';
        $fileTypes = self::normalizeFileTypes($params['file_type'] ?? ['all']);

        $seedRaw = $params['seed'] ?? null;
        $seed = is_numeric($seedRaw) ? (int) $seedRaw : null;

        $hasMaxPreviewedParam = array_key_exists('max_previewed_count', $params);
        $maxPreviewedRaw = $params['max_previewed_count'] ?? null;
        $maxPreviewed = is_numeric($maxPreviewedRaw) ? (int) $maxPreviewedRaw : null;
        if (is_int($maxPreviewed) && $maxPreviewed < 0) {
            $maxPreviewed = null;
        }
        $hasMinPreviewedParam = array_key_exists('min_previewed_count', $params);
        $minPreviewedRaw = $params['min_previewed_count'] ?? null;
        $minPreviewed = is_numeric($minPreviewedRaw) ? (int) $minPreviewedRaw : null;
        if (is_int($minPreviewed) && $minPreviewed < 0) {
            $minPreviewed = null;
        }

        $createdFrom = self::normalizeDateBoundary($params['date_from'] ?? null, false);
        $createdTo = self::normalizeDateBoundary($params['date_to'] ?? null, true);

        if ($createdFrom) {
            $params['date_from'] = $createdFrom['date'];
        } else {
            unset($params['date_from']);
        }

        if ($createdTo) {
            $params['date_to'] = $createdTo['date'];
        } else {
            unset($params['date_to']);
        }

        $reactionMode = is_string($params['reaction_mode'] ?? null) ? (string) $params['reaction_mode'] : 'any';
        $autoBlacklisted = is_string($params['auto_blacklisted'] ?? null) ? (string) $params['auto_blacklisted'] : 'any';
        $reaction = $params['reaction'] ?? null;
        $includeTotal = filter_var($params['include_total'] ?? null, FILTER_VALIDATE_BOOLEAN);

        $allTypes = ['love', 'like', 'funny'];
        $reactionTypes = self::normalizeReactionTypes($reaction, $allTypes);

        if ($reactionMode === 'types' && ($reactionTypes === null || count($reactionTypes) === 0)) {
            return [
                'params' => $params,
                'page' => $page,
                'limit' => $limit,
                'source' => $source,
                'downloaded' => $downloaded,
                'imported' => $imported,
                'notFound' => $notFound,
                'blacklisted' => $blacklisted,
                'sort' => $sort,
                'fileTypes' => $fileTypes,
                'seed' => $seed,
                'maxPreviewed' => $maxPreviewed,
                'minPreviewed' => $minPreviewed,
                'createdFrom' => $createdFrom['timestamp'] ?? null,
                'createdTo' => $createdTo['timestamp'] ?? null,
                'reactionMode' => $reactionMode,
                'autoBlacklisted' => $autoBlacklisted,
                'reactionTypes' => $reactionTypes,
                'includeTotal' => $includeTotal,
                'allTypes' => $allTypes,
                'shouldReturnEmpty' => true,
            ];
        }

        if (! $hasMaxPreviewedParam) {
            $maxPreviewed = null;
            $params['max_previewed_count'] = $maxPreviewed;
        }

        if (! $hasMinPreviewedParam) {
            $minPreviewed = null;
            $params['min_previewed_count'] = $minPreviewed;
        }

        return [
            'params' => $params,
            'page' => $page,
            'limit' => $limit,
            'source' => $source,
            'downloaded' => $downloaded,
            'imported' => $imported,
            'notFound' => $notFound,
            'blacklisted' => $blacklisted,
            'sort' => $sort,
            'fileTypes' => $fileTypes,
            'seed' => $seed,
            'maxPreviewed' => $maxPreviewed,
            'minPreviewed' => $minPreviewed,
            'createdFrom' => $createdFrom['timestamp'] ?? null,
            'createdTo' => $createdTo['timestamp'] ?? null,
            'reactionMode' => $reactionMode,
            'autoBlacklisted' => $autoBlacklisted,
            'reactionTypes' => $reactionTypes,
            'includeTotal' => $includeTotal,
            'allTypes' => $allTypes,
            'shouldReturnEmpty' => false,
        ];
    }

    public static function emptyResponse(): array
    {
        return [
            'files' => [],
            'metadata' => [
                'nextCursor' => null,
                'total' => 0,
            ],
        ];
    }

    private static function normalizeFileTypes(mixed $fileTypeRaw): array
    {
        $fileTypes = self::normalizeStringList($fileTypeRaw);
        $allowedFileTypes = ['all', 'image', 'video', 'audio', 'other'];
        $fileTypes = array_values(array_filter($fileTypes, fn ($type) => in_array($type, $allowedFileTypes, true)));

        if ($fileTypes === [] || in_array('all', $fileTypes, true)) {
            return ['all'];
        }

        return $fileTypes;
    }

    private static function normalizeSources(mixed $sourceRaw): array
    {
        $sources = self::normalizeStringList($sourceRaw);

        if ($sources === [] || in_array('all', $sources, true)) {
            return ['all'];
        }

        return $sources;
    }

    private static function normalizeReactionTypes(mixed $reaction, array $allTypes): ?array
    {
        $reactionTypes = null;
        if (is_array($reaction)) {
            $reactionTypes = self::normalizeStringList($reaction);
        } elseif (is_string($reaction) && $reaction !== '') {
            $reactionTypes = [$reaction];
        }

        if ($reactionTypes === null) {
            return null;
        }

        $reactionTypes = array_values(array_filter($reactionTypes, fn ($type) => in_array($type, $allTypes, true)));

        if (count($reactionTypes) === 0) {
            return [];
        }

        if (count($reactionTypes) === count($allTypes)) {
            return $allTypes;
        }

        return $reactionTypes;
    }

    /**
     * @return array{date: string, timestamp: int}|null
     */
    private static function normalizeDateBoundary(mixed $value, bool $endOfDay): ?array
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $raw));
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $timezone = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
        $date = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
            $timezone,
        );
        $date = $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0);

        return [
            'date' => $date->format('Y-m-d'),
            'timestamp' => $date->getTimestamp(),
        ];
    }

    private static function normalizeStringList(mixed $value): array
    {
        $list = [];
        if (is_array($value)) {
            $list = array_map(static fn ($v) => is_string($v) ? $v : (is_numeric($v) ? (string) $v : ''), $value);
        } elseif (is_string($value) && $value !== '') {
            $list = [$value];
        }

        return array_values(array_unique(array_filter($list)));
    }
}
