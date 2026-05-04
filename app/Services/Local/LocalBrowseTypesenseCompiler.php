<?php

namespace App\Services\Local;

use App\Models\Search\LocalBrowseFileDocument;
use App\Models\Search\LocalBrowseReactionDocument;

class LocalBrowseTypesenseCompiler
{
    private const string EMPTY_FILTER = 'sort_id:=-1';

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function compile(array $context, ?int $userId, ?string $reactionJoinCollection = null): array
    {
        $page = max(1, (int) ($context['page'] ?? 1));
        $limit = max(1, (int) ($context['limit'] ?? 20));
        $sort = (string) ($context['sort'] ?? 'downloaded_at');
        $seed = isset($context['seed']) && is_numeric($context['seed']) ? (int) $context['seed'] : null;

        if ($sort === 'reaction_at' || $sort === 'reaction_at_asc') {
            return $this->compileReactionSearch($context, $userId, $reactionJoinCollection, $page, $limit);
        }

        return [
            'empty' => false,
            'mode' => 'files',
            'model' => LocalBrowseFileDocument::class,
            'collection' => app(LocalBrowseTypesenseNames::class)->filesAlias(),
            'page' => $page,
            'limit' => $limit,
            'options' => [
                'page' => $page,
                'per_page' => $limit,
                'filter_by' => $this->compileFileFilter($context, $userId),
                'sort_by' => $this->compileFileSort((string) ($context['sort'] ?? 'downloaded_at'), $seed),
                'include_fields' => 'id',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function compileReactionSearch(
        array $context,
        ?int $userId,
        ?string $reactionJoinCollection,
        int $page,
        int $limit,
    ): array {
        if (! is_int($userId)) {
            return ['empty' => true];
        }

        $reactionMode = (string) ($context['reactionMode'] ?? 'any');
        $reactionTypes = $context['reactionTypes'] ?? null;
        $allTypes = $context['allTypes'] ?? ['love', 'like', 'funny'];

        if ($reactionMode !== 'types' && $reactionMode !== 'reacted') {
            $reactionMode = 'reacted';
        }

        if ($reactionMode === 'reacted') {
            $reactionTypes = ['love', 'like', 'funny'];
        }

        if (! is_array($reactionTypes) || $reactionTypes === []) {
            return ['empty' => true];
        }

        $normalizedReactionTypes = array_values(array_filter(
            $reactionTypes,
            fn ($type) => is_string($type) && in_array($type, $allTypes, true),
        ));

        if ($normalizedReactionTypes === []) {
            return ['empty' => true];
        }

        $joinCollection = is_string($reactionJoinCollection) && $reactionJoinCollection !== ''
            ? $reactionJoinCollection
            : app(LocalBrowseTypesenseNames::class)->filesAlias();
        $fileFilter = $this->compileFileFilter([
            ...$context,
            'reactionMode' => 'any',
            'reactionTypes' => null,
        ], $userId);

        $filters = [
            'user_id:='.$userId,
            'type:=['.$this->implodeExactValues($normalizedReactionTypes).']',
        ];

        if ($fileFilter !== '') {
            $filters[] = '$'.$joinCollection.'('.$fileFilter.')';
        }

        return [
            'empty' => false,
            'mode' => 'reactions',
            'model' => LocalBrowseReactionDocument::class,
            'collection' => app(LocalBrowseTypesenseNames::class)->reactionsAlias(),
            'page' => $page,
            'limit' => $limit,
            'options' => [
                'page' => $page,
                'per_page' => $limit,
                'filter_by' => implode(' && ', array_values(array_filter($filters))),
                'sort_by' => (string) ($context['sort'] ?? 'reaction_at') === 'reaction_at_asc'
                    ? 'created_at:asc,sort_id:asc'
                    : 'created_at:desc,sort_id:desc',
                'include_fields' => 'file_id',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function compileFileFilter(array $context, ?int $userId): string
    {
        $filters = [];

        $notFound = (string) ($context['notFound'] ?? 'no');
        if ($notFound === 'yes') {
            $filters[] = 'not_found:=true';
        } elseif ($notFound === 'no') {
            $filters[] = 'not_found:=false';
        }

        $source = $context['source'] ?? null;
        if (is_string($source) && $source !== '' && $source !== 'all') {
            $filters[] = 'source:='.$this->quote($source);
        }

        $downloaded = (string) ($context['downloaded'] ?? 'any');
        if ($downloaded === 'yes') {
            $filters[] = 'downloaded:=true';
        } elseif ($downloaded === 'no') {
            $filters[] = 'downloaded:=false';
        }

        $blacklisted = (string) ($context['blacklisted'] ?? 'any');
        if ($blacklisted === 'yes') {
            $filters[] = 'blacklisted:=true';
        } elseif ($blacklisted === 'no') {
            $filters[] = 'blacklisted:=false';
        }

        $maxPreviewed = $context['maxPreviewed'] ?? null;
        if (is_int($maxPreviewed) && $maxPreviewed >= 0) {
            $filters[] = 'previewed_count:<='.(int) $maxPreviewed;
        }

        $fileTypes = is_array($context['fileTypes'] ?? null) ? $context['fileTypes'] : ['all'];
        if (! in_array('all', $fileTypes, true)) {
            $allowedFileTypes = array_values(array_filter(
                $fileTypes,
                fn ($type) => is_string($type) && in_array($type, ['image', 'video', 'audio', 'other'], true),
            ));

            if ($allowedFileTypes !== []) {
                $filters[] = 'mime_group:=['.$this->implodeExactValues($allowedFileTypes).']';
            }
        }

        $autoBlacklisted = (string) ($context['autoBlacklisted'] ?? 'any');
        if ($autoBlacklisted === 'yes') {
            $filters[] = 'auto_blacklisted:=true';
        } elseif ($autoBlacklisted === 'no') {
            $filters[] = 'auto_blacklisted:=false';
        }

        $reactionMode = (string) ($context['reactionMode'] ?? 'any');
        $reactionTypes = is_array($context['reactionTypes'] ?? null) ? $context['reactionTypes'] : null;

        if ($reactionMode === 'reacted') {
            if (! is_int($userId)) {
                return self::EMPTY_FILTER;
            }

            $filters[] = '(love_user_ids:=['.$userId.'] || like_user_ids:=['.$userId.'] || funny_user_ids:=['.$userId.'])';
        } elseif ($reactionMode === 'types') {
            if (! is_int($userId) || ! is_array($reactionTypes) || $reactionTypes === []) {
                return self::EMPTY_FILTER;
            }

            $typedFilters = array_map(
                fn ($type) => $type.'_user_ids:=['.$userId.']',
                array_values(array_filter(
                    $reactionTypes,
                    fn ($type) => is_string($type) && in_array($type, ['love', 'like', 'funny'], true),
                )),
            );

            if ($typedFilters === []) {
                return self::EMPTY_FILTER;
            }

            $filters[] = '('.implode(' || ', $typedFilters).')';
        } elseif ($reactionMode === 'unreacted') {
            if (! is_int($userId)) {
                return self::EMPTY_FILTER;
            }

            $filters[] = 'reacted_user_ids:!=['.$userId.']';
        }

        return implode(' && ', array_values(array_filter($filters)));
    }

    private function compileFileSort(string $sort, ?int $seed): string
    {
        return match ($sort) {
            'created_at' => 'created_at:desc,sort_id:desc',
            'created_at_asc' => 'created_at:asc,sort_id:asc',
            'updated_at' => 'updated_at:desc,sort_id:desc',
            'updated_at_asc' => 'updated_at:asc,sort_id:asc',
            'blacklisted_at' => 'blacklisted_at:desc,updated_at:desc,sort_id:desc',
            'blacklisted_at_asc' => 'blacklisted_at:asc,updated_at:asc,sort_id:asc',
            'downloaded_at_asc' => 'downloaded_at:asc,updated_at:asc,sort_id:asc',
            'random' => '_rand('.($seed ?? time()).'):desc,sort_id:desc',
            default => 'downloaded_at:desc,updated_at:desc,sort_id:desc',
        };
    }

    /**
     * @param  array<int, string>  $values
     */
    private function implodeExactValues(array $values): string
    {
        return implode(', ', array_map(fn ($value) => $this->quote($value), $values));
    }

    private function quote(string $value): string
    {
        return '`'.str_replace('`', '\`', $value).'`';
    }
}
