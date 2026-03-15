<?php

use App\Support\ContainerBrowseTabPayload;

it('builds civitai browse payloads for version-precise resource containers', function () {
    $payload = ContainerBrowseTabPayload::build([
        'type' => 'LoRA',
        'source' => 'CivitAI',
        'source_id' => '1545615',
    ], [
        'limit' => 40,
    ]);

    expect($payload)->toBe([
        'label' => 'CivitAI Images: LoRA 1545615 - 1',
        'params' => [
            'feed' => 'online',
            'service' => 'civit-ai-images',
            'page' => 1,
            'limit' => 40,
            'modelVersionId' => '1545615',
        ],
    ]);
});
