<?php

use App\Enums\ActionType;
use App\Models\BrowseTab;
use App\Models\Container;
use App\Models\File;
use App\Models\ModerationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('immediately auto-disliked files are excluded from browse response items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create an active moderation rule with immediate auto_dislike action
    ModerationRule::factory()->any(['girl'])->create([
        'name' => 'Block girl term',
        'active' => true,
        'action_type' => ActionType::AUTO_DISLIKE,
    ]);

    // Mock CivitAI API response with files that will match the rule
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'civitai.com/api/v1/images')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 123,
                        'url' => 'https://image.civitai.com/xmpq/file1.jpg',
                        'meta' => [
                            'prompt' => 'girl with flowers',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'abc123',
                    ],
                    [
                        'id' => 456,
                        'url' => 'https://image.civitai.com/xmpq/file2.jpg',
                        'meta' => [
                            'prompt' => 'beautiful landscape',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'def456',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    // Create a tab
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Test Tab',
        'query_params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
    ]);

    // Make browse request
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);

    $response->assertSuccessful();
    $data = $response->json();

    // Get file IDs from the response items
    $returnedFileIds = collect($data['items'])->pluck('id')->toArray();

    // Find files by referrer URL (CivitAI service uses https://civitai.com/images/{id} as referrer_url)
    $matchedFile = File::where('referrer_url', 'https://civitai.com/images/123')->first();
    $nonMatchedFile = File::where('referrer_url', 'https://civitai.com/images/456')->first();

    // Both files should exist
    expect($matchedFile)->not->toBeNull()
        ->and($nonMatchedFile)->not->toBeNull();

    // Assert matched file is auto-disliked in database
    expect($matchedFile->fresh()->auto_disliked)->toBeTrue();

    // Assert matched file is NOT in the returned items
    expect($returnedFileIds)->not->toContain($matchedFile->id);

    // Assert non-matched file IS in the returned items
    expect($returnedFileIds)->toContain($nonMatchedFile->id);

    // Assert matched file is NOT attached to the tab
    expect($tab->files()->where('file_id', $matchedFile->id)->exists())->toBeFalse();

    // Assert non-matched file IS attached to the tab
    expect($tab->files()->where('file_id', $nonMatchedFile->id)->exists())->toBeTrue();

    // Assert moderation data shows the file was processed
    expect($data['moderation'])->not->toBeNull()
        ->and($data['moderation']['processed_ids'])->toContain($matchedFile->id)
        ->and($data['moderation']['immediate_actions'])->not->toBeEmpty()
        ->and(count($data['moderation']['immediate_actions']))->toBe(1)
        ->and($data['moderation']['immediate_actions'][0]['id'])->toBe($matchedFile->id)
        ->and($data['moderation']['immediate_actions'][0]['action_type'])->toBe('auto_dislike')
        ->and($data['moderation']['immediate_actions'][0]['thumbnail'])->not->toBeEmpty();
});

test('immediately blacklisted files are excluded from browse response items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create an active moderation rule with immediate blacklist action
    ModerationRule::factory()->any(['girl'])->create([
        'name' => 'Block girl term',
        'active' => true,
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Mock CivitAI API response
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'civitai.com/api/v1/images')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 123,
                        'url' => 'https://image.civitai.com/xmpq/file1.jpg',
                        'meta' => [
                            'prompt' => 'girl with flowers',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'abc123',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    // Create a tab
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Test Tab',
        'query_params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
    ]);

    // Make browse request
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);

    $response->assertSuccessful();
    $data = $response->json();

    // Get file IDs from the response items
    $returnedFileIds = collect($data['items'])->pluck('id')->toArray();

    // Find the file that matched the rule by referrer URL
    $matchedFile = File::where('referrer_url', 'https://civitai.com/images/123')->first();

    // File should exist
    expect($matchedFile)->not->toBeNull();

    // Assert matched file is blacklisted in database
    expect($matchedFile->fresh()->blacklisted_at)->not->toBeNull();

    // Assert matched file is NOT in the returned items
    expect($returnedFileIds)->not->toContain($matchedFile->id);

    // Assert matched file is NOT attached to the tab
    expect($tab->files()->where('file_id', $matchedFile->id)->exists())->toBeFalse();

    // Assert moderation data shows the file was processed
    expect($data['moderation'])->not->toBeNull()
        ->and($data['moderation']['processed_ids'])->toContain($matchedFile->id)
        ->and($data['moderation']['immediate_actions'])->not->toBeEmpty()
        ->and(count($data['moderation']['immediate_actions']))->toBe(1)
        ->and($data['moderation']['immediate_actions'][0]['id'])->toBe($matchedFile->id)
        ->and($data['moderation']['immediate_actions'][0]['action_type'])->toBe('blacklist')
        ->and($data['moderation']['immediate_actions'][0]['thumbnail'])->not->toBeEmpty();
});

test('immediately auto-disliked files from blacklisted containers are excluded from browse response items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock CivitAI API response
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'civitai.com/api/v1/images')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 123,
                        'url' => 'https://image.civitai.com/xmpq/file1.jpg',
                        'meta' => [
                            'prompt' => 'beautiful landscape',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'abc123',
                    ],
                    [
                        'id' => 456,
                        'url' => 'https://image.civitai.com/xmpq/file2.jpg',
                        'meta' => [
                            'prompt' => 'another image',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'def456',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    // Create a tab
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Test Tab',
        'query_params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
    ]);

    // Make browse request to get files persisted
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);
    $response->assertSuccessful();

    // Find the file that will be matched
    $matchedFile = File::where('referrer_url', 'https://civitai.com/images/123')->first();
    $nonMatchedFile = File::where('referrer_url', 'https://civitai.com/images/456')->first();

    // Both files should exist
    expect($matchedFile)->not->toBeNull()
        ->and($nonMatchedFile)->not->toBeNull();

    // Create a blacklisted container with auto_dislike action type
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'user123',
        'blacklisted_at' => now(),
        'action_type' => ActionType::AUTO_DISLIKE,
    ]);

    // Attach container to the matched file only
    $matchedFile->containers()->attach($container->id);

    // Make another browse request - the matched file should now be auto-disliked
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);
    $response->assertSuccessful();
    $data = $response->json();

    // Get file IDs from the response items
    $returnedFileIds = collect($data['items'])->pluck('id')->toArray();

    // Assert matched file is auto-disliked in database
    expect($matchedFile->fresh()->auto_disliked)->toBeTrue();

    // Assert matched file is NOT in the returned items
    expect($returnedFileIds)->not->toContain($matchedFile->id);

    // Assert non-matched file IS in the returned items
    expect($returnedFileIds)->toContain($nonMatchedFile->id);

    // Note: Files from the first request were attached to the tab before the container was blacklisted
    // After the second request, the matched file is auto-disliked and filtered out from the response
    // The important assertion is that the file is excluded from the response and processed correctly

    // Assert moderation data shows the file was processed
    expect($data['moderation'])->not->toBeNull()
        ->and($data['moderation']['processed_ids'])->toContain($matchedFile->id)
        ->and($data['moderation']['immediate_actions'])->not->toBeEmpty()
        ->and(count($data['moderation']['immediate_actions']))->toBe(1)
        ->and($data['moderation']['immediate_actions'][0]['id'])->toBe($matchedFile->id)
        ->and($data['moderation']['immediate_actions'][0]['action_type'])->toBe('auto_dislike')
        ->and($data['moderation']['immediate_actions'][0]['thumbnail'])->not->toBeEmpty();
});

test('immediately blacklisted files from blacklisted containers are excluded from browse response items', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Mock CivitAI API response
    Http::fake(function ($request) {
        $url = $request->url();
        if (str_contains($url, 'civitai.com/api/v1/images')) {
            return Http::response([
                'items' => [
                    [
                        'id' => 123,
                        'url' => 'https://image.civitai.com/xmpq/file1.jpg',
                        'meta' => [
                            'prompt' => 'beautiful landscape',
                            'width' => 640,
                            'height' => 640,
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'abc123',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    // Create a tab
    $tab = BrowseTab::factory()->for($user)->create([
        'label' => 'Test Tab',
        'query_params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
    ]);

    // Make browse request to get files persisted
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);
    $response->assertSuccessful();

    // Find the file that will be matched
    $matchedFile = File::where('referrer_url', 'https://civitai.com/images/123')->first();

    // File should exist
    expect($matchedFile)->not->toBeNull();

    // Create a blacklisted container with blacklist action type
    $container = Container::factory()->create([
        'type' => 'User',
        'source' => 'CivitAI',
        'source_id' => 'user123',
        'blacklisted_at' => now(),
        'action_type' => ActionType::BLACKLIST,
    ]);

    // Attach container to the file
    $matchedFile->containers()->attach($container->id);

    // Make another browse request - the file should now be blacklisted
    $response = $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id);
    $response->assertSuccessful();
    $data = $response->json();

    // Get file IDs from the response items
    $returnedFileIds = collect($data['items'])->pluck('id')->toArray();

    // Assert matched file is blacklisted in database
    expect($matchedFile->fresh()->blacklisted_at)->not->toBeNull();

    // Assert matched file is NOT in the returned items
    expect($returnedFileIds)->not->toContain($matchedFile->id);

    // Note: The file was attached to the tab in the first request before the container was blacklisted
    // After blacklisting, the file is filtered out from the response but may still be in the tab
    // The important thing is that it's excluded from the response and blacklisted

    // Assert moderation data shows the file was processed
    expect($data['moderation'])->not->toBeNull()
        ->and($data['moderation']['processed_ids'])->toContain($matchedFile->id)
        ->and($data['moderation']['immediate_actions'])->not->toBeEmpty()
        ->and(count($data['moderation']['immediate_actions']))->toBe(1)
        ->and($data['moderation']['immediate_actions'][0]['id'])->toBe($matchedFile->id)
        ->and($data['moderation']['immediate_actions'][0]['action_type'])->toBe('blacklist')
        ->and($data['moderation']['immediate_actions'][0]['thumbnail'])->not->toBeEmpty();
});
