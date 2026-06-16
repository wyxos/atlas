<?php

use App\Enums\ActionType;
use App\Jobs\DownloadFile;
use App\Models\File;
use App\Models\ModerationRule;
use App\Models\Reaction;
use App\Models\User;
use App\Services\CivitAiImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/ExtensionReactionsTestSupport.php';

uses(RefreshDatabase::class);

function companionCivitaiImageItem(int $id, string $url, string $prompt = 'portrait study', array $overrides = []): array
{
    return [
        'request_id' => 'image-'.$id,
        'id' => $id,
        'url' => $url,
        'type' => str_ends_with($url, '.mp4') ? 'video' : 'image',
        'nsfw' => false,
        'width' => 1024,
        'height' => 1536,
        'hash' => 'hash-'.$id,
        'postId' => 8800000 + $id,
        'username' => 'companionCreator',
        'meta' => [
            'prompt' => $prompt,
            'negativePrompt' => 'low quality',
            'seed' => $id,
        ],
        'resource_containers' => [
            [
                'type' => 'Checkpoint',
                'modelId' => 9303001,
                'modelVersionId' => 9404001,
                'referrerUrl' => 'https://civitai.com/models/9303001/example-checkpoint?modelVersionId=9404001',
            ],
        ],
        ...$overrides,
    ];
}

test('companion civitai status reports downloaded media and Atlas filter reasons', function () {
    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $downloadedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/11111111-1111-4111-8111-111111111111/original=true/11111111-1111-4111-8111-111111111111.jpeg';
    $blacklistedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/22222222-2222-4222-8222-222222222222/original=true/22222222-2222-4222-8222-222222222222.jpeg';
    $ruleMatchedUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/33333333-3333-4333-8333-333333333333/original=true/33333333-3333-4333-8333-333333333333.jpeg';

    $downloadedFile = File::factory()->create([
        'source' => CivitAiImages::SOURCE,
        'source_id' => '9103001',
        'url' => $downloadedUrl,
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
    ]);

    Reaction::query()->create([
        'file_id' => $downloadedFile->id,
        'user_id' => $user->id,
        'type' => 'like',
    ]);

    $blacklistedFile = File::factory()->create([
        'source' => CivitAiImages::SOURCE,
        'source_id' => '9103002',
        'url' => $blacklistedUrl,
        'blacklisted_at' => now()->subDay(),
    ]);

    ModerationRule::factory()->any(['blockedterm'])->create([
        'name' => 'Blocked prompt term',
        'action_type' => ActionType::BLACKLIST,
    ]);

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/civitai/status', [
        'items' => [
            companionCivitaiImageItem(9103001, $downloadedUrl, 'already saved portrait'),
            companionCivitaiImageItem(9103002, $blacklistedUrl, 'ordinary prompt'),
            companionCivitaiImageItem(9103003, $ruleMatchedUrl, 'contains blockedterm in prompt'),
        ],
    ]);

    $response->assertSuccessful();
    $response->assertJsonCount(3, 'items');

    $response->assertJsonPath('items.0.request_id', 'image-9103001');
    $response->assertJsonPath('items.0.exists', true);
    $response->assertJsonPath('items.0.file_id', $downloadedFile->id);
    $response->assertJsonPath('items.0.downloaded', true);
    $response->assertJsonPath('items.0.reaction', 'like');
    $response->assertJsonPath('items.0.filtered', false);

    $response->assertJsonPath('items.1.file_id', $blacklistedFile->id);
    $response->assertJsonPath('items.1.blacklisted', true);
    $response->assertJsonPath('items.1.filtered', true);
    $response->assertJsonPath('items.1.filter_reasons.0.type', 'blacklisted');

    $response->assertJsonPath('items.2.exists', false);
    $response->assertJsonPath('items.2.filtered', true);
    $response->assertJsonPath('items.2.filter_reasons.0.type', 'moderation_rule');
    $response->assertJsonPath('items.2.filter_reasons.0.name', 'Blocked prompt term');
    expect(File::query()->where('source_id', '9103003')->exists())->toBeFalse();
});

test('companion civitai reactions persist browse metadata and queue the Atlas download path', function () {
    Queue::fake();

    $user = User::factory()->create();
    setExtensionReactionApiKey('valid-api-key', $user->id);

    $url = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/44444444-4444-4444-8444-444444444444/original=true/44444444-4444-4444-8444-444444444444.jpeg';

    $response = $this->withHeaders([
        'X-Atlas-Api-Key' => 'valid-api-key',
    ])->postJson('/api/extension/civitai/reactions', [
        'type' => 'love',
        'download_behavior' => 'queue',
        'item' => companionCivitaiImageItem(9103010, $url, 'download this companion prompt'),
    ]);

    $response->assertSuccessful();
    $response->assertJsonPath('file.source', CivitAiImages::SOURCE);
    $response->assertJsonPath('file.source_id', '9103010');
    $response->assertJsonPath('reaction.type', 'love');
    $response->assertJsonPath('download.requested', true);

    $file = File::query()->where('source', CivitAiImages::SOURCE)->where('source_id', '9103010')->first();

    expect($file)->not->toBeNull();
    expect(data_get($file?->metadata?->payload, 'prompt'))->toBe('download this companion prompt');
    expect(data_get($file?->listing_metadata, 'resource_containers.0.modelVersionId'))->toBe(9404001);
    expect($file?->containers()->where('type', 'Checkpoint')->where('source_id', '9404001')->exists())->toBeTrue();
    expect(Reaction::query()->where('file_id', $file?->id)->where('user_id', $user->id)->value('type'))->toBe('love');

    Queue::assertPushed(DownloadFile::class, function (DownloadFile $job) use ($file, $user): bool {
        return $job->fileId === $file?->id
            && ($job->runtimeContext['user_id'] ?? null) === $user->id;
    });
});
