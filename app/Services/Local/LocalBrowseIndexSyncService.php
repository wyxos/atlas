<?php

namespace App\Services\Local;

use App\Models\Reaction;
use App\Models\Search\LocalBrowseFileDocument;
use App\Models\Search\LocalBrowseReactionDocument;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class LocalBrowseIndexSyncService
{
    public function __construct(
        private Client $client,
        private LocalBrowseTypesenseNames $names,
        private LocalBrowseTypesenseSchemaFactory $schemaFactory,
    ) {}

    /**
     * @param  array<int, int>  $fileIds
     */
    public function syncFilesByIds(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);
        $collection = $this->names->currentFilesCollection();

        if ($fileIds === [] || ! is_string($collection) || $collection === '') {
            return;
        }

        $documents = LocalBrowseFileDocument::query()
            ->whereIn('id', $fileIds)
            ->with([
                'reactions' => fn ($builder) => $builder->select(['id', 'file_id', 'user_id', 'type']),
            ])
            ->get()
            ->map(fn (LocalBrowseFileDocument $file) => $file->toSearchableArray())
            ->values()
            ->all();

        if ($documents === []) {
            return;
        }

        $this->importDocuments($collection, $documents);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function syncReactionsForFileIds(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);
        $collection = $this->names->currentReactionsCollection();

        if ($fileIds === [] || ! is_string($collection) || $collection === '') {
            return;
        }

        $this->deleteDocuments($collection, 'file_id:=['.$this->implodeIds($fileIds).']');

        $documents = LocalBrowseReactionDocument::query()
            ->whereIn('file_id', $fileIds)
            ->get()
            ->map(fn (LocalBrowseReactionDocument $reaction) => $reaction->toSearchableArray())
            ->values()
            ->all();

        if ($documents === []) {
            return;
        }

        $this->importDocuments($collection, $documents);
    }

    /**
     * @param  array<int, int>  $reactionIds
     */
    public function syncReactionIds(array $reactionIds): void
    {
        $reactionIds = $this->normalizeIds($reactionIds);
        $collection = $this->names->currentReactionsCollection();

        if ($reactionIds === [] || ! is_string($collection) || $collection === '') {
            return;
        }

        $documents = LocalBrowseReactionDocument::query()
            ->whereIn('id', $reactionIds)
            ->get()
            ->map(fn (LocalBrowseReactionDocument $reaction) => $reaction->toSearchableArray())
            ->values()
            ->all();

        if ($documents === []) {
            return;
        }

        $this->importDocuments($collection, $documents);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function deleteFilesByIds(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);

        if ($fileIds === []) {
            return;
        }

        $filesCollection = $this->names->currentFilesCollection();
        if (is_string($filesCollection) && $filesCollection !== '') {
            foreach ($fileIds as $fileId) {
                try {
                    $this->client->getCollections()[$filesCollection]->getDocuments()[(string) $fileId]->delete();
                } catch (ObjectNotFound|TypesenseClientError) {
                    // Stale or already-removed docs should not break caller writes.
                }
            }
        }

        $this->deleteReactionsForFileIds($fileIds);
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    public function deleteReactionsForFileIds(array $fileIds): void
    {
        $fileIds = $this->normalizeIds($fileIds);
        $collection = $this->names->currentReactionsCollection();

        if ($fileIds === [] || ! is_string($collection) || $collection === '') {
            return;
        }

        $this->deleteDocuments($collection, 'file_id:=['.$this->implodeIds($fileIds).']');
    }

    public function deleteAll(): void
    {
        foreach ([$this->names->currentFilesCollection(), $this->names->currentReactionsCollection()] as $collection) {
            if (! is_string($collection) || $collection === '') {
                continue;
            }

            $this->deleteDocuments($collection, 'id:*');
        }
    }

    public function rebuild(string $suffix, ?callable $progress = null): array
    {
        $filesCollection = $this->names->filesCollection($suffix);
        $reactionsCollection = $this->names->reactionsCollection($suffix);

        $this->createOrReplaceCollection($filesCollection, $this->schemaFactory->fileSchema($filesCollection));

        $fileChunkSize = max(1, (int) config('local_browse.typesense.chunk', 500));
        LocalBrowseFileDocument::query()
            ->with([
                'reactions' => fn ($builder) => $builder->select(['id', 'file_id', 'user_id', 'type']),
            ])
            ->orderBy('id')
            ->chunkById($fileChunkSize, function ($files) use ($filesCollection, $progress): void {
                $documents = $files
                    ->map(fn (LocalBrowseFileDocument $file) => $file->toSearchableArray())
                    ->values()
                    ->all();

                $this->importDocuments($filesCollection, $documents);

                if ($progress) {
                    $progress('files', count($documents));
                }
            });

        $this->client->getAliases()->upsert($this->names->filesAlias(), [
            'collection_name' => $filesCollection,
        ]);
        $this->names->forgetAlias($this->names->filesAlias());

        $this->createOrReplaceCollection(
            $reactionsCollection,
            $this->schemaFactory->reactionSchema($reactionsCollection, $filesCollection),
        );

        LocalBrowseReactionDocument::query()
            ->orderBy('id')
            ->chunkById($fileChunkSize, function ($reactions) use ($reactionsCollection, $progress): void {
                $documents = $reactions
                    ->map(fn (LocalBrowseReactionDocument $reaction) => $reaction->toSearchableArray())
                    ->values()
                    ->all();

                $this->importDocuments($reactionsCollection, $documents);

                if ($progress) {
                    $progress('reactions', count($documents));
                }
            });

        $this->client->getAliases()->upsert($this->names->reactionsAlias(), [
            'collection_name' => $reactionsCollection,
        ]);
        $this->names->forgetAlias($this->names->reactionsAlias());

        return [
            'files_alias' => $this->names->filesAlias(),
            'files_collection' => $filesCollection,
            'reactions_alias' => $this->names->reactionsAlias(),
            'reactions_collection' => $reactionsCollection,
            'files_total' => (int) LocalBrowseFileDocument::query()->count(),
            'reactions_total' => (int) Reaction::query()->count(),
        ];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, fn ($id) => is_numeric($id)))));
    }

    /**
     * @param  array<int, array<string, mixed>>  $documents
     */
    private function importDocuments(string $collection, array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $results = $this->client->getCollections()[$collection]
            ->getDocuments()
            ->import($documents, ['action' => 'upsert']);

        foreach ($results as $result) {
            if (($result['success'] ?? false) !== true) {
                $message = is_string($result['error'] ?? null) ? $result['error'] : 'Unknown Typesense import error.';

                throw new \RuntimeException($message);
            }
        }
    }

    private function deleteDocuments(string $collection, string $filter): void
    {
        try {
            $this->client->getCollections()[$collection]
                ->getDocuments()
                ->delete(['filter_by' => $filter]);
        } catch (ObjectNotFound|TypesenseClientError) {
            // Missing aliases or already-removed docs should not fail primary writes.
        }
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function implodeIds(array $ids): string
    {
        return implode(', ', array_map(static fn (int $id): string => (string) $id, $ids));
    }

    private function createOrReplaceCollection(string $collection, array $schema): void
    {
        try {
            $this->client->getCollections()[$collection]->delete();
        } catch (ObjectNotFound|TypesenseClientError) {
            // Fresh collection names are expected most of the time.
        }

        $this->client->getCollections()->create($schema);
    }
}
