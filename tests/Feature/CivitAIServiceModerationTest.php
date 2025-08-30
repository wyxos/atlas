<?php

use App\Models\ModerationRule;
use App\Services\CivitAIService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

it('blacklists image items by prompt moderation and returns only safe files', function () {
    // Active rule to block prompts containing the word "toilet"
    ModerationRule::create([
        'name' => 'Block toilet',
        'type' => 'contains',
        'terms' => ['toilet'],
        'match' => 'any',
        'action' => 'block',
        'active' => true,
    ]);

    // Fake CivitAI images API response with one safe and one offending item
    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 1001,
                    'url' => 'https://image.civitai.com/x/safe.jpeg',
                    'width' => 640,
                    'height' => 480,
                    'meta' => [
                        'prompt' => 'A cute kitten in a basket',
                    ],
                ],
                [
                    'id' => 1002,
                    'url' => 'https://image.civitai.com/x/bad.jpeg',
                    'width' => 640,
                    'height' => 480,
                    'meta' => [
                        'prompt' => 'A toilet scene in a bathroom',
                    ],
                ],
            ],
            'metadata' => [
                'nextCursor' => null,
            ],
        ], 200),
    ]);

    $service = new CivitAIService(new Request(['page' => 1]));
    $result = $service->fetch();

    // Only the safe item should be returned to the UI
    expect($result['items'])->toHaveCount(1);
    $item = $result['items'][0];
    expect($item['src'])->toBe('https://image.civitai.com/x/safe.jpeg');

    // The offending file should be blacklisted in DB
    $this->assertDatabaseHas('files', [
        'source' => 'CivitAI',
        'source_id' => '1002',
        'is_blacklisted' => 1,
    ]);

    // The safe file should not be blacklisted
    $this->assertDatabaseHas('files', [
        'source' => 'CivitAI',
        'source_id' => '1001',
        'is_blacklisted' => 0,
    ]);
});

it('blacklists by combo rule requiring all primary terms and any with-term', function () {
    // Rule: terms must include both alpha and beta, and with any of x or y
    ModerationRule::create([
        'name' => 'Block alpha+beta with x|y',
        'type' => 'contains-combo',
        'terms' => ['alpha', 'beta'],
        'with_terms' => ['x', 'y'],
        'match' => 'all',
        'action' => 'block',
        'active' => true,
    ]);

    Http::fake([
        'civitai.com/api/v1/images*' => Http::response([
            'items' => [
                [
                    'id' => 2001,
                    'url' => 'https://image.civitai.com/x/safe2.jpeg',
                    'width' => 640,
                    'height' => 480,
'meta' => [ 'prompt' => 'alpha with x' ],
                ],
                [
                    'id' => 2002,
                    'url' => 'https://image.civitai.com/x/bad2.jpeg',
                    'width' => 640,
                    'height' => 480,
                    'meta' => [ 'prompt' => 'alpha beta with y' ],
                ],
            ],
            'metadata' => [ 'nextCursor' => null ],
        ], 200),
    ]);

    $service = new CivitAIService(new Request(['page' => 1]));
    $result = $service->fetch();

    // Only the safe one remains (missing beta)
    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['src'])->toBe('https://image.civitai.com/x/safe2.jpeg');

    // Confirm blacklisted in DB
    $this->assertDatabaseHas('files', [
        'source' => 'CivitAI',
        'source_id' => '2002',
        'is_blacklisted' => 1,
    ]);
});

