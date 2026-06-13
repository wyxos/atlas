<?php

use App\Services\CivitAiImages;

uses(Tests\TestCase::class);

it('requests full flat metadata from civitai listings', function () {
    $service = new CivitAiImages([
        'limit' => 25,
        'page' => '2|1781279460000',
    ]);

    expect($service->formatParams())->toMatchArray([
        'limit' => 25,
        'cursor' => '2|1781279460000',
        'withMeta' => 'true',
        'flatMeta' => 'true',
    ]);
});

it('stores flat civitai metadata in the metadata payload', function () {
    $service = new CivitAiImages;

    $result = $service->transform([
        'items' => [
            [
                'id' => 133523267,
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d406b89a-0c27-474c-891a-62a599ea58eb/original=true/d406b89a-0c27-474c-891a-62a599ea58eb.jpeg',
                'type' => 'image',
                'nsfw' => false,
                'width' => 832,
                'height' => 1216,
                'meta' => [
                    'prompt' => 'flat prompt',
                    'negativePrompt' => 'flat negative prompt',
                ],
            ],
        ],
        'metadata' => [
            'nextCursor' => null,
        ],
    ]);

    $payload = json_decode($result['files'][0]['metadata']['payload'], true);

    expect($payload)->toMatchArray([
        'prompt' => 'flat prompt',
        'negativePrompt' => 'flat negative prompt',
        'width' => 832,
        'height' => 1216,
    ]);
});

it('stores nested civitai image-id metadata in the metadata payload', function () {
    $service = new CivitAiImages;

    $result = $service->transform([
        'items' => [
            [
                'id' => 133523267,
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/d406b89a-0c27-474c-891a-62a599ea58eb/original=true/d406b89a-0c27-474c-891a-62a599ea58eb.jpeg',
                'type' => 'image',
                'nsfw' => false,
                'width' => 832,
                'height' => 1216,
                'meta' => [
                    'id' => 133523267,
                    'meta' => [
                        'prompt' => 'nested prompt',
                        'negativePrompt' => 'nested negative prompt',
                    ],
                ],
            ],
        ],
        'metadata' => [
            'nextCursor' => null,
        ],
    ]);

    $payload = json_decode($result['files'][0]['metadata']['payload'], true);

    expect($payload)->toMatchArray([
        'id' => 133523267,
        'prompt' => 'nested prompt',
        'negativePrompt' => 'nested negative prompt',
        'width' => 832,
        'height' => 1216,
    ]);
});

it('uses civitai red page referrers for nsfw image rows', function () {
    $service = new CivitAiImages;

    $result = $service->transform([
        'items' => [
            [
                'id' => 9101001,
                'url' => 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/8928e082-af52-4ade-a86e-d79e0ed63aa9/original=true/8928e082-af52-4ade-a86e-d79e0ed63aa9.jpeg',
                'type' => 'image',
                'nsfw' => true,
                'width' => 1024,
                'height' => 1536,
            ],
        ],
        'metadata' => [
            'nextCursor' => null,
        ],
    ]);

    expect($result['files'][0]['file']['referrer_url'])->toBe('https://civitai.red/images/9101001')
        ->and($result['files'][0]['metadata']['file_referrer_url'])->toBe('https://civitai.red/images/9101001');
});

it('uses civitai red container referrers for nsfw metadata', function () {
    $service = new CivitAiImages;

    expect($service->containers([
        'postId' => 9202001,
        'username' => 'exampleCreator',
        'nsfwLevel' => 'Mature',
        'resource_containers' => [
            [
                'type' => 'LoRA',
                'modelId' => 9303002,
                'modelVersionId' => 9404002,
                'referrerUrl' => 'https://civitai.com/models/9303002/example-lora?modelVersionId=9404002',
            ],
        ],
    ]))->toMatchArray([
        [
            'type' => 'Post',
            'source_id' => '9202001',
            'referrer' => 'https://civitai.red/posts/9202001',
        ],
        [
            'type' => 'User',
            'source_id' => 'exampleCreator',
            'referrer' => 'https://civitai.red/user/exampleCreator',
        ],
        [
            'type' => 'LoRA',
            'source_id' => '9404002',
            'referrer' => 'https://civitai.red/models/9303002/example-lora?modelVersionId=9404002',
        ],
    ]);
});
