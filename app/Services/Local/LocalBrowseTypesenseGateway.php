<?php

namespace App\Services\Local;

use App\Exceptions\LocalBrowseUnavailableException;
use Throwable;

class LocalBrowseTypesenseGateway
{
    public function __construct(
        private LocalBrowseTypesenseCompiler $compiler,
        private LocalBrowseTypesenseNames $names,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{files: array<int, \App\Models\File>, metadata: array{nextCursor: int|null, total: int}}
     */
    public function search(array $context): array
    {
        $sort = (string) ($context['sort'] ?? 'downloaded_at');
        $this->ensureAliasesAvailable($sort);

        $compiled = $this->compiler->compile($context, auth()->id(), $this->names->currentReactionJoinCollection());

        if (($compiled['empty'] ?? false) === true) {
            return LocalFetchParams::emptyResponse();
        }

        try {
            $results = $this->runScoutSearch($compiled);
        } catch (LocalBrowseUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new LocalBrowseUnavailableException(previous: $e);
        }

        $ids = $this->extractIds($compiled, $results);
        $total = (int) ($results['found'] ?? 0);
        $page = (int) ($compiled['page'] ?? 1);
        $limit = max(1, (int) ($compiled['limit'] ?? 20));

        return [
            'files' => LocalBrowseQueryBuilder::hydrateFiles($ids),
            'metadata' => [
                'nextCursor' => ($page * $limit) < $total ? $page + 1 : null,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $compiled
     * @return array<string, mixed>
     */
    protected function runScoutSearch(array $compiled): array
    {
        $modelClass = $compiled['model'];
        $callback = static fn ($documents, $query, $options) => $documents->search($options);

        /** @var array<string, mixed> $results */
        $results = $modelClass::search('*', $callback)
            ->within((string) $compiled['collection'])
            ->take((int) $compiled['limit'])
            ->options((array) $compiled['options'])
            ->raw();

        return $results;
    }

    private function ensureAliasesAvailable(string $sort): void
    {
        if (! $this->names->hasFilesAlias()) {
            throw new LocalBrowseUnavailableException;
        }

        if (($sort === 'reaction_at' || $sort === 'reaction_at_asc') && ! $this->names->hasReactionsAlias()) {
            throw new LocalBrowseUnavailableException;
        }
    }

    /**
     * @param  array<string, mixed>  $compiled
     * @param  array<string, mixed>  $results
     * @return array<int, int>
     */
    private function extractIds(array $compiled, array $results): array
    {
        $hits = $results['hits'] ?? [];
        if (! is_array($hits)) {
            return [];
        }

        return collect($hits)
            ->map(function ($hit) use ($compiled): ?int {
                if (! is_array($hit) || ! isset($hit['document']) || ! is_array($hit['document'])) {
                    return null;
                }

                $key = ($compiled['mode'] ?? 'files') === 'reactions' ? 'file_id' : 'id';
                $value = $hit['document'][$key] ?? null;

                return is_numeric($value) ? (int) $value : null;
            })
            ->filter(fn ($id) => is_int($id))
            ->values()
            ->all();
    }
}
