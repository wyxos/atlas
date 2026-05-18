<?php

use App\Support\ContainerBrowseTabPayload;

it('builds civitai browse payloads for version-precise resource containers', function () {
    $payload = ContainerBrowseTabPayload::build([
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '9404002',
    ], [
        'limit' => 40,
    ]);

    expect($payload)->toBe([
        'label' => 'CivitAI Images: LoRA 9404002 - 1',
        'params' => [
            'feed' => 'online',
            'service' => 'civit-ai-images',
            'page' => 1,
            'limit' => 40,
            'modelVersionId' => '9404002',
        ],
    ]);
});

it('builds DeviantArt browse payloads for user containers', function () {
    $payload = ContainerBrowseTabPayload::build([
        'type' => 'User',
        'source' => 'deviantart.com',
        'source_id' => 'artist',
    ], [
        'limit' => 24,
        'tag' => 'landscape',
        'nsfw' => true,
    ]);

    expect($payload)->toBe([
        'label' => 'DeviantArt Images: User artist - 1',
        'params' => [
            'feed' => 'online',
            'service' => 'deviantart-images',
            'page' => 1,
            'limit' => 24,
            'username' => 'artist',
            'tag' => '',
            'q' => '',
            'folderId' => '',
        ],
    ]);
});
