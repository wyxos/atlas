<?php

namespace App\Services\Local;

use Typesense\Client;
use Typesense\Exceptions\TypesenseClientError;

class LocalBrowseTypesenseNames
{
    /**
     * @var array<string, string|null>
     */
    private array $resolvedAliases = [];

    public function __construct(
        private Client $client,
    ) {}

    public function filesAlias(): string
    {
        return (string) config('local_browse.typesense.files_alias');
    }

    public function reactionsAlias(): string
    {
        return (string) config('local_browse.typesense.reactions_alias');
    }

    public function versionSuffix(?string $suffix = null): string
    {
        return $suffix ?: now()->utc()->format('Ymd_His');
    }

    public function filesCollection(string $suffix): string
    {
        return $this->filesAlias().'__v'.$this->versionSuffix($suffix);
    }

    public function reactionsCollection(string $suffix): string
    {
        return $this->reactionsAlias().'__v'.$this->versionSuffix($suffix);
    }

    public function currentFilesCollection(): ?string
    {
        return $this->resolveAliasTarget($this->filesAlias());
    }

    public function currentReactionsCollection(): ?string
    {
        return $this->resolveAliasTarget($this->reactionsAlias());
    }

    public function currentReactionJoinCollection(): ?string
    {
        $reactionCollection = $this->currentReactionsCollection();
        if (! is_string($reactionCollection) || $reactionCollection === '') {
            return $this->currentFilesCollection();
        }

        $reactionsBase = $this->reactionsAlias().'__v';
        if (str_starts_with($reactionCollection, $reactionsBase)) {
            return $this->filesAlias().'__v'.substr($reactionCollection, strlen($reactionsBase));
        }

        return $this->currentFilesCollection();
    }

    public function hasFilesAlias(): bool
    {
        return is_string($this->currentFilesCollection());
    }

    public function hasReactionsAlias(): bool
    {
        return is_string($this->currentReactionsCollection());
    }

    public function resolveAliasTarget(string $alias): ?string
    {
        if (array_key_exists($alias, $this->resolvedAliases)) {
            return $this->resolvedAliases[$alias];
        }

        try {
            $mapping = $this->client->getAliases()[$alias]->retrieve();

            $collectionName = $mapping['collection_name'] ?? null;

            return $this->resolvedAliases[$alias] = is_string($collectionName) && $collectionName !== ''
                ? $collectionName
                : null;
        } catch (TypesenseClientError) {
            return $this->resolvedAliases[$alias] = null;
        }
    }

    public function forgetAlias(string $alias): void
    {
        unset($this->resolvedAliases[$alias]);
    }
}
