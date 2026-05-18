<?php

namespace App\Support;

class DeviantArtImagesFilterSchema
{
    public static function make(): array
    {
        $schema = ServiceFilterSchema::make()
            ->keys([
                'page' => 'offset',
                'nsfw' => 'mature_content',
            ])
            ->types([
                'page' => 'hidden',
                'limit' => 'number',
                'q' => 'text',
                'tag' => 'text',
                'username' => 'text',
                'folderId' => 'text',
                'nsfw' => 'boolean',
            ])
            ->labels([
                'q' => 'Search',
                'folderId' => 'Folder ID',
                'nsfw' => 'Mature Content',
            ]);

        return $schema->fields([
            $schema->pageField(),
            $schema->limitField([
                'description' => 'The number of results per page (1-50; gallery endpoints max at 24).',
                'min' => 1,
                'max' => 50,
            ]),
            $schema->field('q', [
                'description' => 'Search DeviantArt home results.',
                'placeholder' => 'Search DeviantArt',
            ]),
            $schema->field('tag', [
                'description' => 'Browse a tag. Takes priority over search.',
                'placeholder' => 'nature',
            ]),
            $schema->field('username', [
                'description' => 'Browse a user gallery.',
                'placeholder' => 'artistname',
            ]),
            $schema->field('folderId', [
                'description' => 'Browse a specific gallery folder UUID.',
                'placeholder' => 'Gallery folder UUID',
            ]),
            $schema->field('nsfw', [
                'description' => 'Include mature content when the authenticated app may access it.',
            ]),
        ]);
    }
}
