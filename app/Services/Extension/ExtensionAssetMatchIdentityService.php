<?php

namespace App\Services\Extension;

use App\Models\ExtensionAssetMatchIdentity;
use App\Models\File;
use App\Models\Reaction;
use App\Support\ExtensionAssetMatchUrlCleanup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExtensionAssetMatchIdentityService
{
    private const MATCH_BY_SOURCE = 'source';

    private const MATCH_BY_REFERRER = 'referrer';

    public function __construct(private readonly ExtensionAssetMatchUrlCleanup $urlCleanup) {}

    /**
     * @param  array<string, mixed>|null  $identity
     */
    public function upsertForFile(File $file, mixed $identity): void
    {
        $normalized = $this->normalizeIdentity($identity);
        if ($normalized === null) {
            return;
        }

        ExtensionAssetMatchIdentity::query()->updateOrCreate([
            'file_id' => $file->id,
            'match_by' => $normalized['match_by'],
            'match_url_hash' => hash('sha256', $normalized['match_url']),
            'rule_digest' => $normalized['rule_digest'],
        ], [
            'match_url' => $normalized['match_url'],
            'rule_id' => $normalized['rule_id'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, File|null>
     */
    public function filesByMatchItems(array $items, int $userId): array
    {
        $normalizedItems = $this->normalizeMatchItems($items);
        if ($normalizedItems === []) {
            return [];
        }

        $hashesByMatchBy = [];
        foreach ($normalizedItems as $item) {
            $hashesByMatchBy[$item['match_by']][] = hash('sha256', $item['match_url']);
        }

        $identities = ExtensionAssetMatchIdentity::query()
            ->with('file')
            ->where(function (Builder $query) use ($hashesByMatchBy): void {
                foreach ($hashesByMatchBy as $matchBy => $hashes) {
                    $query->orWhere(function (Builder $query) use ($matchBy, $hashes): void {
                        $query->where('match_by', $matchBy)
                            ->whereIn('match_url_hash', array_values(array_unique($hashes)));
                    });
                }
            })
            ->get();

        $identitiesByKey = [];
        $fileIds = [];
        foreach ($identities as $identity) {
            if (! $identity->file instanceof File) {
                continue;
            }

            $key = $this->matchKey($identity->match_by, $identity->match_url);
            $identitiesByKey[$key][] = $identity;
            $fileIds[] = (int) $identity->file->id;
        }

        $positiveReactionsByFileId = $fileIds === []
            ? []
            : Reaction::query()
                ->where('user_id', $userId)
                ->whereIn('file_id', array_values(array_unique($fileIds)))
                ->whereIn('type', $this->positiveReactionTypes())
                ->pluck('type', 'file_id')
                ->all();

        $matches = [];
        foreach ($normalizedItems as $item) {
            $candidates = collect($identitiesByKey[$this->matchKey($item['match_by'], $item['match_url'])] ?? [])
                ->map(fn (ExtensionAssetMatchIdentity $identity): ?File => $identity->file)
                ->filter(fn (?File $file): bool => $file instanceof File)
                ->unique('id')
                ->sortByDesc(fn (File $file): string => sprintf(
                    '%020d:%020d',
                    $file->updated_at?->getTimestamp() ?? 0,
                    (int) $file->id,
                ))
                ->values();

            $matches[$item['lookup_id']] = $this->chooseStatusFile(
                $candidates,
                $item['match_by'],
                $positiveReactionsByFileId,
            );
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    public function previewRule(array $rule, int $limit = 50): array
    {
        $normalizedRule = $this->normalizeRule($rule);
        if ($normalizedRule === null) {
            return [
                'candidate_count' => 0,
                'identity_count' => 0,
                'sample' => [],
            ];
        }

        $query = $this->filesForRuleQuery($normalizedRule);
        $sample = [];
        foreach ((clone $query)->limit(max(1, $limit))->get() as $file) {
            $identity = $this->identityForFileRule($file, $normalizedRule);
            if ($identity === null) {
                continue;
            }

            $sample[] = [
                'file_id' => (int) $file->id,
                'match_by' => $identity['match_by'],
                'match_url' => $identity['match_url'],
            ];
        }

        return [
            'candidate_count' => (clone $query)->count(),
            'identity_count' => count($sample),
            'sample' => $sample,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return Collection<int, File>
     */
    public function filesForRuleChunk(array $rule, int $afterId, int $chunk): Collection
    {
        $normalizedRule = $this->normalizeRule($rule);
        if ($normalizedRule === null) {
            return collect();
        }

        return $this->filesForRuleQuery($normalizedRule)
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit(max(1, $chunk))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<string, string>|null
     */
    public function identityForFileRule(File $file, array $rule): ?array
    {
        $normalizedRule = $this->normalizeRule($rule);
        if ($normalizedRule === null) {
            return null;
        }

        $rawUrl = $normalizedRule['match_by'] === self::MATCH_BY_REFERRER
            ? $file->referrer_url
            : $file->url;
        $matchUrl = $this->normalizeUrl($rawUrl);
        if ($matchUrl === null || ! $this->urlCleanup->urlMatchesDomain($matchUrl, $normalizedRule['domain'])) {
            return null;
        }

        $cleanUrl = $this->urlCleanup->apply($matchUrl, $normalizedRule['cleanup']);

        return [
            'match_by' => $normalizedRule['match_by'],
            'match_url' => $cleanUrl,
            'rule_digest' => $normalizedRule['rule_digest'],
            'rule_id' => $normalizedRule['rule_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function upsertForFileRule(File $file, array $rule): void
    {
        $identity = $this->identityForFileRule($file, $rule);
        if ($identity !== null) {
            $this->upsertForFile($file, $identity);
        }
    }

    /**
     * @return array{match_by: string, match_url: string, rule_id: string|null, rule_digest: string}|null
     */
    private function normalizeIdentity(mixed $identity): ?array
    {
        if (! is_array($identity)) {
            return null;
        }

        $matchBy = $this->normalizeMatchBy($identity['match_by'] ?? null);
        $matchUrl = $this->normalizeUrl($identity['match_url'] ?? null);
        if ($matchBy === null || $matchUrl === null) {
            return null;
        }

        return [
            'match_by' => $matchBy,
            'match_url' => $matchUrl,
            'rule_digest' => $this->normalizeString($identity['rule_digest'] ?? ''),
            'rule_id' => $this->normalizeOptionalString($identity['rule_id'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{lookup_id: string, match_by: string, match_url: string}>
     */
    private function normalizeMatchItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $lookupId = $this->normalizeString($item['lookup_id'] ?? '');
            $matchBy = $this->normalizeMatchBy($item['match_by'] ?? null);
            $matchUrl = $this->normalizeUrl($item['match_url'] ?? null);
            if ($lookupId === '' || $matchBy === null || $matchUrl === null) {
                continue;
            }

            $normalized[] = [
                'lookup_id' => $lookupId,
                'match_by' => $matchBy,
                'match_url' => $matchUrl,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{match_by: string, domain: string|null, cleanup: mixed, rule_id: string|null, rule_digest: string}|null
     */
    private function normalizeRule(array $rule): ?array
    {
        $matchBy = $this->normalizeMatchBy($rule['match_by'] ?? null);
        if ($matchBy === null) {
            return null;
        }

        $domain = $this->normalizeDomain($rule['domain'] ?? null);
        $ruleId = $this->normalizeOptionalString($rule['rule_id'] ?? null);
        $ruleDigest = $this->normalizeString($rule['rule_digest'] ?? '');
        if ($ruleDigest === '') {
            $ruleDigest = hash('sha256', json_encode([
                'cleanup' => $rule['cleanup'] ?? null,
                'domain' => $domain,
                'match_by' => $matchBy,
                'rule_id' => $ruleId,
            ], JSON_THROW_ON_ERROR));
        }

        return [
            'cleanup' => $rule['cleanup'] ?? [],
            'domain' => $domain,
            'match_by' => $matchBy,
            'rule_digest' => $ruleDigest,
            'rule_id' => $ruleId,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return Builder<File>
     */
    private function filesForRuleQuery(array $rule): Builder
    {
        $column = $rule['match_by'] === self::MATCH_BY_REFERRER ? 'referrer_url' : 'url';

        return File::query()
            ->select(['id', 'url', 'referrer_url', 'updated_at', 'blacklisted_at'])
            ->whereNotNull($column)
            ->when($rule['domain'] !== null, function (Builder $query) use ($column, $rule): void {
                $query->where($column, 'like', '%'.$rule['domain'].'%');
            });
    }

    /**
     * @param  Collection<int, File>  $candidates
     * @param  array<int|string, string>  $positiveReactionsByFileId
     */
    private function chooseStatusFile(Collection $candidates, string $matchBy, array $positiveReactionsByFileId): ?File
    {
        if ($candidates->isEmpty()) {
            return null;
        }

        $positive = $candidates->first(
            fn (File $file): bool => isset($positiveReactionsByFileId[$file->id])
        );
        if ($positive instanceof File) {
            return $positive;
        }

        $blacklisted = $candidates->first(
            fn (File $file): bool => $file->blacklisted_at !== null
        );
        if ($blacklisted instanceof File) {
            return $blacklisted;
        }

        return $matchBy === self::MATCH_BY_SOURCE ? $candidates->first() : null;
    }

    private function normalizeMatchBy(mixed $value): ?string
    {
        $normalized = $this->normalizeString($value);

        return in_array($normalized, [self::MATCH_BY_SOURCE, self::MATCH_BY_REFERRER], true)
            ? $normalized
            : null;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true)
            ? $trimmed
            : null;
    }

    private function normalizeDomain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = strtolower(trim($value));
        if ($candidate === '') {
            return null;
        }

        $host = str_contains($candidate, '://') ? parse_url($candidate, PHP_URL_HOST) : $candidate;
        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(trim($host, ". \t\n\r\0\x0B"));

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host) === 1 ? $host : null;
    }

    private function normalizeString(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function matchKey(string $matchBy, string $matchUrl): string
    {
        return $matchBy.'|'.hash('sha256', $matchUrl);
    }

    /**
     * @return list<string>
     */
    private function positiveReactionTypes(): array
    {
        return ['love', 'like', 'funny'];
    }
}
