<?php

namespace App\Support;

class CivitAiImagesFilterSchema
{
    public static function make(): array
    {
        $schema = ServiceFilterSchema::make()
            ->keys([
                'page' => 'cursor',
            ])
            ->types([
                'page' => 'hidden',
                'limit' => 'number',
                'postId' => 'number',
                'modelId' => 'number',
                'modelVersionId' => 'number',
                'username' => 'text',
                'nsfw' => 'boolean',
                'type' => 'radio',
                'sort' => 'select',
                'period' => 'select',
            ])
            ->labels([
                'postId' => 'Post ID',
                'modelId' => 'Model ID',
                'modelVersionId' => 'Model Version ID',
                'nsfw' => 'NSFW',
            ]);

        return $schema->fields([
            ...$schema->paginationFields(),

            $schema->field('postId', [
                'placeholder' => 'The ID of a post to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('modelId', [
                'placeholder' => 'The ID of a model to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('modelVersionId', [
                'placeholder' => 'The ID of a model version to get images from.',
                'min' => 1,
                'step' => 1,
            ]),
            $schema->field('username', [
                'placeholder' => 'Filter to images from a specific user (e.g. someUser).',
            ]),
            $schema->field('nsfw', [
                'description' => 'Include NSFW results.',
            ]),
            $schema->field('type', [
                'description' => 'Filter by media type.',
                'options' => [
                    ['label' => 'All', 'value' => 'all'],
                    ['label' => 'Image', 'value' => 'image'],
                    ['label' => 'Video', 'value' => 'video'],
                ],
            ]),
            $schema->field('sort', [
                'description' => 'Order of results.',
                'options' => [
                    ['label' => 'Newest', 'value' => 'Newest'],
                    ['label' => 'Most Reactions', 'value' => 'Most Reactions'],
                    ['label' => 'Most Comments', 'value' => 'Most Comments'],
                ],
            ]),
            $schema->field('period', [
                'description' => 'Time window for sorting.',
                'options' => [
                    ['label' => 'All Time', 'value' => 'AllTime'],
                    ['label' => 'Year', 'value' => 'Year'],
                    ['label' => 'Month', 'value' => 'Month'],
                    ['label' => 'Week', 'value' => 'Week'],
                    ['label' => 'Day', 'value' => 'Day'],
                ],
            ]),
        ]);
    }
}
