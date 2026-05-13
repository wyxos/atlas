<?php

namespace App\Models\Search;

use App\Models\Reaction;
use App\Services\Library\LibraryTypesenseNames;
use App\Services\Library\LibraryTypesenseSchemaFactory;
use Laravel\Scout\Searchable;

class LibraryReactionDocument extends Reaction
{
    use Searchable;

    protected $table = 'reactions';

    public function searchableAs()
    {
        return app(LibraryTypesenseNames::class)->reactionsAlias();
    }

    public function indexableAs()
    {
        return $this->searchableAs();
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'sort_id' => (int) $this->id,
            'file_id' => (string) $this->file_id,
            'user_id' => (string) $this->user_id,
            'type' => (string) $this->type,
            'created_at' => $this->created_at?->getTimestamp() ?? 0,
            'updated_at' => $this->updated_at?->getTimestamp() ?? 0,
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return app(LibraryTypesenseSchemaFactory::class)->reactionSchema(
            $this->searchableAs(),
            app(LibraryTypesenseNames::class)->filesAlias(),
        );
    }

    public function typesenseSearchParameters(): array
    {
        return [
            'query_by' => 'type,user_id',
            'prefix' => false,
        ];
    }
}
