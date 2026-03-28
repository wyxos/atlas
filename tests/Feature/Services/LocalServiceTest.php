<?php

use App\Models\File;
use App\Models\Reaction;
use App\Models\User;
use App\Services\LocalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(LocalService::class);
});

function createLocalFile(array $overrides = []): File
{
    return File::factory()->create([
        'downloaded' => true,
        'downloaded_at' => now()->subHour(),
        'blacklisted_at' => null,
        'blacklist_reason' => null,
        'auto_disliked' => false,
        'previewed_count' => 0,
        'source' => 'CivitAI',
        'mime_type' => 'image/jpeg',
        ...$overrides,
    ]);
}

function addReaction(File $file, User $user, string $type, \DateTimeInterface $createdAt): void
{
    Reaction::query()->insert([
        'file_id' => $file->id,
        'user_id' => $user->id,
        'type' => $type,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

function localFileIds(array $result): array
{
    return collect($result['files'])->pluck('id')->all();
}

test('returns correct key, source, and label', function () {
    expect(LocalService::key())->toBe('local');
    expect(LocalService::source())->toBe('Local');
    expect(LocalService::label())->toBe('Local Files');
});

test('fetch returns paginated local files ordered by downloaded_at descending', function () {
    $oldest = createLocalFile(['downloaded_at' => now()->subDays(2)]);
    $middle = createLocalFile(['downloaded_at' => now()->subDay()]);
    $latest = createLocalFile(['downloaded_at' => now()->subHour()]);

    $result = $this->service->fetch(['page' => 1, 'limit' => 20]);

    expect(localFileIds($result))->toBe([
        $latest->id,
        $middle->id,
        $oldest->id,
    ]);
    expect($result['metadata']['nextCursor'])->toBeNull();
    expect($result['metadata']['total'])->toBe(3);
});

test('fetch filters by source, downloaded state, and file type', function () {
    $matching = createLocalFile([
        'source' => 'Wallhaven',
        'downloaded' => false,
        'mime_type' => 'video/mp4',
    ]);
    createLocalFile([
        'source' => 'Wallhaven',
        'downloaded' => false,
        'mime_type' => 'image/jpeg',
    ]);
    createLocalFile([
        'source' => 'CivitAI',
        'downloaded' => false,
        'mime_type' => 'video/mp4',
    ]);
    createLocalFile([
        'source' => 'Wallhaven',
        'downloaded' => true,
        'mime_type' => 'video/mp4',
    ]);

    $result = $this->service->fetch([
        'source' => 'Wallhaven',
        'downloaded' => 'no',
        'file_type' => ['video'],
    ]);

    expect(localFileIds($result))->toBe([$matching->id]);
    expect($result['metadata']['total'])->toBe(1);
});

test('fetch filters by blacklisted state and blacklist type', function () {
    $manual = createLocalFile([
        'blacklisted_at' => now()->subHour(),
        'blacklist_reason' => 'Manual blacklist',
    ]);
    $auto = createLocalFile([
        'blacklisted_at' => now()->subMinutes(30),
        'blacklist_reason' => '',
    ]);
    createLocalFile(['blacklisted_at' => null]);

    $manualResult = $this->service->fetch([
        'blacklisted' => 'yes',
        'blacklist_type' => 'manual',
    ]);

    $autoResult = $this->service->fetch([
        'blacklisted' => 'yes',
        'blacklist_type' => 'auto',
    ]);

    expect(localFileIds($manualResult))->toBe([$manual->id]);
    expect(localFileIds($autoResult))->toBe([$auto->id]);
});

test('fetch filters by auto disliked and max previewed count', function () {
    $matching = createLocalFile([
        'auto_disliked' => true,
        'previewed_count' => 2,
    ]);
    createLocalFile([
        'auto_disliked' => false,
        'previewed_count' => 2,
    ]);
    createLocalFile([
        'auto_disliked' => true,
        'previewed_count' => 4,
    ]);

    $result = $this->service->fetch([
        'auto_disliked' => 'yes',
        'max_previewed_count' => 2,
    ]);

    expect(localFileIds($result))->toBe([$matching->id]);
    expect($result['metadata']['total'])->toBe(1);
});

test('fetch supports reacted, typed, and unreacted modes for the current user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $reacted = createLocalFile();
    $typed = createLocalFile();
    $otherUsersReaction = createLocalFile();
    $unreacted = createLocalFile();

    addReaction($reacted, $user, 'like', now()->subMinutes(30));
    addReaction($typed, $user, 'dislike', now()->subMinutes(20));
    addReaction($otherUsersReaction, User::factory()->create(), 'like', now()->subMinutes(10));

    $reactedResult = $this->service->fetch([
        'reaction_mode' => 'reacted',
    ]);

    $typedResult = $this->service->fetch([
        'reaction_mode' => 'types',
        'reaction' => ['dislike'],
    ]);

    $unreactedResult = $this->service->fetch([
        'reaction_mode' => 'unreacted',
    ]);

    expect(localFileIds($reactedResult))->toBe([$reacted->id]);
    expect(localFileIds($typedResult))->toBe([$typed->id]);
    expect(localFileIds($unreactedResult))->toEqualCanonicalizing([
        $otherUsersReaction->id,
        $unreacted->id,
    ]);
});

test('fetch supports the auto disliked or auto blacklisted moderation union', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $autoDisliked = createLocalFile([
        'auto_disliked' => true,
        'blacklisted_at' => null,
    ]);
    $autoBlacklisted = createLocalFile([
        'auto_disliked' => false,
        'blacklisted_at' => now()->subHour(),
        'blacklist_reason' => '',
    ]);
    createLocalFile([
        'auto_disliked' => false,
        'blacklisted_at' => now()->subHour(),
        'blacklist_reason' => 'Manual blacklist',
    ]);

    addReaction($autoDisliked, $user, 'dislike', now()->subMinutes(10));

    $result = $this->service->fetch([
        'moderation_union' => LocalService::MODERATION_UNION_AUTO_DISLIKED_OR_BLACKLISTED_AUTO,
    ]);

    expect(localFileIds($result))->toEqualCanonicalizing([
        $autoDisliked->id,
        $autoBlacklisted->id,
    ]);
});

test('fetch orders by reaction timestamp and includes totals when requested', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $older = createLocalFile();
    $newer = createLocalFile();

    addReaction($older, $user, 'love', now()->subMinutes(10));
    addReaction($newer, $user, 'funny', now()->subMinute());

    $desc = $this->service->fetch([
        'reaction_mode' => 'reacted',
        'sort' => 'reaction_at',
        'include_total' => 1,
    ]);

    $asc = $this->service->fetch([
        'reaction_mode' => 'reacted',
        'sort' => 'reaction_at_asc',
        'include_total' => 1,
    ]);

    expect(localFileIds($desc))->toBe([$newer->id, $older->id]);
    expect(localFileIds($asc))->toBe([$older->id, $newer->id]);
    expect($desc['metadata']['total'])->toBe(2);
    expect($asc['metadata']['total'])->toBe(2);
});

test('random sort is stable for the same seed and changes for a different seed', function () {
    File::factory()->count(30)->create([
        'downloaded' => true,
        'blacklisted_at' => null,
        'auto_disliked' => false,
    ]);

    $first = $this->service->fetch([
        'sort' => 'random',
        'seed' => 12345,
        'limit' => 10,
    ]);

    $sameSeed = $this->service->fetch([
        'sort' => 'random',
        'seed' => 12345,
        'limit' => 10,
    ]);

    $differentSeed = $this->service->fetch([
        'sort' => 'random',
        'seed' => 54321,
        'limit' => 10,
    ]);

    expect(localFileIds($first))->toBe(localFileIds($sameSeed));
    expect(localFileIds($differentSeed))->not->toBe(localFileIds($first));
});

test('transform returns file models directly and includes cursor metadata', function () {
    $file = createLocalFile();

    $fetchResult = $this->service->fetch(['page' => 1, 'limit' => 20]);
    $transformResult = $this->service->transform($fetchResult);

    expect($transformResult['files'][0])->toBeInstanceOf(File::class);
    expect($transformResult['files'][0]->id)->toBe($file->id);
    expect($transformResult['filter'])->toHaveKey('next');
    expect($transformResult['meta']['total'])->toBe(1);
});

test('default params return the expected defaults', function () {
    expect($this->service->defaultParams())->toBe([
        'limit' => 20,
        'source' => 'all',
    ]);
});
