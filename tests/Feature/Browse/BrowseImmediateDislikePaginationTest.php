<?php

use App\Models\Container;
use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('civitai browse excludes later page items after blacklisting a user container between requests using the same persisted user container fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $requestCount = 0;

    Http::fake(function ($request) use (&$requestCount) {
        $url = $request->url();
        if (str_contains($url, 'civitai.com/api/v1/images')) {
            $requestCount++;

            if ($requestCount === 1) {
                return Http::response([
                    'items' => [
                        [
                            'id' => 123,
                            'url' => 'https://image.civitai.com/xmpq/file1.jpg',
                            'username' => 'baraisgreat',
                            'meta' => [
                                'prompt' => 'first page blocked user image',
                            ],
                            'width' => 640,
                            'height' => 640,
                            'type' => 'image',
                            'hash' => 'abc123',
                        ],
                        [
                            'id' => 456,
                            'url' => 'https://image.civitai.com/xmpq/file2.jpg',
                            'username' => 'user456',
                            'meta' => [
                                'prompt' => 'first page allowed image',
                            ],
                            'width' => 640,
                            'height' => 640,
                            'type' => 'image',
                            'hash' => 'def456',
                        ],
                    ],
                    'metadata' => [
                        'nextCursor' => 'cursor-2',
                    ],
                ], 200);
            }

            return Http::response([
                'items' => [
                    [
                        'id' => 789,
                        'url' => 'https://image.civitai.com/xmpq/file3.jpg',
                        'username' => 'baraisgreat',
                        'meta' => [
                            'prompt' => 'second page blocked user image',
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'ghi789',
                    ],
                    [
                        'id' => 987,
                        'url' => 'https://image.civitai.com/xmpq/file4.jpg',
                        'username' => 'user987',
                        'meta' => [
                            'prompt' => 'second page allowed image',
                        ],
                        'width' => 640,
                        'height' => 640,
                        'type' => 'image',
                        'hash' => 'jkl987',
                    ],
                ],
                'metadata' => [
                    'nextCursor' => null,
                ],
            ], 200);
        }

        return Http::response([], 200);
    });

    $tab = Tab::factory()->for($user)->create([
        'label' => 'Test Tab',
        'params' => [
            'service' => 'civit-ai-images',
            'page' => 1,
        ],
    ]);

    $this->getJson('/api/browse?source=civit-ai-images&page=1&tab_id='.$tab->id)->assertSuccessful();

    $container = Container::query()
        ->where('type', 'User')
        ->where('source', 'CivitAI')
        ->where('source_id', 'baraisgreat')
        ->first();

    expect($container)->not->toBeNull()
        ->and($container->referrer)->toBe('https://civitai.com/user/baraisgreat')
        ->and($container->action_type)->toBeNull()
        ->and($container->blacklisted_at)->toBeNull();

    $this->postJson('/api/container-blacklists', [
        'container_id' => $container->id,
        'action_type' => 'blacklist',
    ])->assertCreated();

    $response = $this->getJson('/api/browse?source=civit-ai-images&page=cursor-2&tab_id='.$tab->id);
    $response->assertSuccessful();

    $returnedFileIds = collect($response->json('items'))->pluck('id')->all();
    $blockedFile = File::where('referrer_url', 'https://civitai.com/images/789')->first();
    $visibleFile = File::where('referrer_url', 'https://civitai.com/images/987')->first();

    expect($blockedFile)->not->toBeNull()
        ->and($visibleFile)->not->toBeNull()
        ->and($blockedFile->fresh()->blacklisted_at)->not->toBeNull()
        ->and($blockedFile->fresh()->containers()->pluck('containers.id')->all())->toContain($container->id)
        ->and(
            Container::query()
                ->where('type', 'User')
                ->where('source', 'CivitAI')
                ->where('source_id', 'baraisgreat')
                ->count()
        )->toBe(1)
        ->and($returnedFileIds)->not->toContain($blockedFile->id)
        ->and($returnedFileIds)->toContain($visibleFile->id);
});
