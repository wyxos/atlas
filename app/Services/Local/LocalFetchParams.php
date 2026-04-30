<?php

namespace App\Services\Local;

class LocalFetchParams
{
    /**
     * @return array{
     *   params: array<string, mixed>,
     *   page: int,
     *   limit: int,
     *   source: ?string,
     *   downloaded: string,
     *   blacklisted: string,
     *   sort: string,
     *   fileTypes: array<int, string>,
     *   seed: ?int,
     *   maxPreviewed: ?int,
     *   reactionMode: string,
     *   autoDisliked: string,
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
        $source = is_string($params['source'] ?? null) ? (string) $params['source'] : null;
        $downloaded = is_string($params['downloaded'] ?? null) ? (string) $params['downloaded'] : 'any';
        $blacklisted = is_string($params['blacklisted'] ?? null) ? (string) $params['blacklisted'] : 'any';
        $sort = is_string($params['sort'] ?? null) ? (string) $params['sort'] : 'downloaded_at';
        $fileTypes = self::normalizeFileTypes($params['file_type'] ?? ['all']);

        $seedRaw = $params['seed'] ?? null;
        $seed = is_numeric($seedRaw) ? (int) $seedRaw : null;

        $hasMaxPreviewedParam = array_key_exists('max_previewed_count', $params);
        $maxPreviewedRaw = $params['max_previewed_count'] ?? null;
        $maxPreviewed = is_numeric($maxPreviewedRaw) ? (int) $maxPreviewedRaw : null;
        if (is_int($maxPreviewed) && $maxPreviewed < 0) {
            $maxPreviewed = null;
        }

        $reactionMode = is_string($params['reaction_mode'] ?? null) ? (string) $params['reaction_mode'] : 'any';
        $autoDisliked = is_string($params['auto_disliked'] ?? null) ? (string) $params['auto_disliked'] : 'any';
        $reaction = $params['reaction'] ?? null;
        $includeTotal = filter_var($params['include_total'] ?? null, FILTER_VALIDATE_BOOLEAN);

        $allTypes = ['love', 'like', 'dislike', 'funny'];
        $reactionTypes = self::normalizeReactionTypes($reaction, $allTypes);

        if ($reactionMode === 'types' && ($reactionTypes === null || count($reactionTypes) === 0)) {
            return [
                'params' => $params,
                'page' => $page,
                'limit' => $limit,
                'source' => $source,
                'downloaded' => $downloaded,
                'blacklisted' => $blacklisted,
                'sort' => $sort,
                'fileTypes' => $fileTypes,
                'seed' => $seed,
                'maxPreviewed' => $maxPreviewed,
                'reactionMode' => $reactionMode,
                'autoDisliked' => $autoDisliked,
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

        return [
            'params' => $params,
            'page' => $page,
            'limit' => $limit,
            'source' => $source,
            'downloaded' => $downloaded,
            'blacklisted' => $blacklisted,
            'sort' => $sort,
            'fileTypes' => $fileTypes,
            'seed' => $seed,
            'maxPreviewed' => $maxPreviewed,
            'reactionMode' => $reactionMode,
            'autoDisliked' => $autoDisliked,
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
