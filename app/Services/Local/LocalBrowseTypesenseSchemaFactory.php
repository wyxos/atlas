<?php

namespace App\Services\Local;

class LocalBrowseTypesenseSchemaFactory
{
    public function fileSchema(string $collectionName): array
    {
        return [
            'name' => $collectionName,
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'sort_id', 'type' => 'int64'],
                ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'mime_group', 'type' => 'string', 'facet' => true],
                ['name' => 'mime_type', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'previewed_count', 'type' => 'int32', 'optional' => true],
                ['name' => 'blacklisted', 'type' => 'bool', 'optional' => true, 'facet' => true],
                ['name' => 'blacklisted_at', 'type' => 'int64', 'optional' => true],
                ['name' => 'blacklist_type', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'downloaded', 'type' => 'bool', 'optional' => true, 'facet' => true],
                ['name' => 'downloaded_at', 'type' => 'int64', 'optional' => true],
                ['name' => 'not_found', 'type' => 'bool', 'optional' => true, 'facet' => true],
                ['name' => 'auto_disliked', 'type' => 'bool', 'optional' => true, 'facet' => true],
                ['name' => 'love_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'like_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'dislike_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'funny_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'reacted_user_ids', 'type' => 'string[]', 'optional' => true, 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64'],
                ['name' => 'updated_at', 'type' => 'int64'],
            ],
            'default_sorting_field' => 'sort_id',
        ];
    }

    public function reactionSchema(string $collectionName, string $fileCollectionName): array
    {
        return [
            'name' => $collectionName,
            'enable_nested_fields' => true,
            'fields' => [
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'sort_id', 'type' => 'int64'],
                [
                    'name' => 'file_id',
                    'type' => 'string',
                    'reference' => $fileCollectionName.'.id',
                    'async_reference' => true,
                ],
                ['name' => 'user_id', 'type' => 'string', 'facet' => true],
                ['name' => 'type', 'type' => 'string', 'facet' => true],
                ['name' => 'created_at', 'type' => 'int64'],
                ['name' => 'updated_at', 'type' => 'int64'],
            ],
            'default_sorting_field' => 'sort_id',
        ];
    }
}
