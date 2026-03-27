<?php

use App\Events\FileMarkedNotFound;
use App\Models\File;
use App\Models\Tab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('preview failure marks civitai files as not found, detaches them from all tabs, and broadcasts affected tabs', function () {
    Event::fake([FileMarkedNotFound::class]);

    $requester = User::factory()->admin()->create();
    $otherUser = User::factory()->admin()->create();

    $previewUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/example-guid/width=1216/example-guid.jpeg';
    $originalUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/example-guid/original=true/example-guid.jpeg';

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => $originalUrl,
        'preview_url' => $previewUrl,
        'path' => null,
        'preview_path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $requesterTab = Tab::factory()->for($requester)->withFiles([$file->id])->create();
    $otherTabA = Tab::factory()->for($otherUser)->withFiles([$file->id])->create();
    $otherTabB = Tab::factory()->for($otherUser)->withFiles([$file->id])->create();

    Http::fake([
        $previewUrl => Http::response('', 404),
        $originalUrl => Http::response('', 404),
    ]);

    $response = $this->actingAs($requester)->postJson("/api/files/{$file->id}/preview-failure");

    $response->assertNoContent();

    $file->refresh();
    $requesterTab->refresh();
    $otherTabA->refresh();
    $otherTabB->refresh();

    expect($file->not_found)->toBeTrue()
        ->and($requesterTab->files()->count())->toBe(0)
        ->and($otherTabA->files()->count())->toBe(0)
        ->and($otherTabB->files()->count())->toBe(0);

    Event::assertDispatchedTimes(FileMarkedNotFound::class, 2);
    Event::assertDispatched(FileMarkedNotFound::class, function (FileMarkedNotFound $event) use ($file, $requesterTab, $requester) {
        return $event->userId === $requester->id
            && $event->fileId === $file->id
            && $event->tabIds === [$requesterTab->id];
    });
    Event::assertDispatched(FileMarkedNotFound::class, function (FileMarkedNotFound $event) use ($file, $otherTabA, $otherTabB, $otherUser) {
        $tabIds = $event->tabIds;
        sort($tabIds);

        return $event->userId === $otherUser->id
            && $event->fileId === $file->id
            && $tabIds === [$otherTabA->id, $otherTabB->id];
    });
});

test('preview failure does not mark file when only one civitai url returns 404', function () {
    Event::fake([FileMarkedNotFound::class]);

    $user = User::factory()->admin()->create();
    $previewUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/example-guid-2/width=1216/example-guid-2.jpeg';
    $originalUrl = 'https://image.civitai.com/xG1nkqKTMzGDvpLrqFT7WA/example-guid-2/original=true/example-guid-2.jpeg';

    $file = File::factory()->create([
        'source' => 'CivitAI',
        'url' => $originalUrl,
        'preview_url' => $previewUrl,
        'path' => null,
        'preview_path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $tab = Tab::factory()->for($user)->withFiles([$file->id])->create();

    Http::fake([
        $previewUrl => Http::response('', 404),
        $originalUrl => Http::response('', 200),
    ]);

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/preview-failure");

    $response->assertNoContent();

    $file->refresh();
    $tab->refresh();

    expect($file->not_found)->toBeFalse()
        ->and($tab->files()->count())->toBe(1);

    Event::assertNotDispatched(FileMarkedNotFound::class);
});

test('preview failure ignores non civitai files', function () {
    Event::fake([FileMarkedNotFound::class]);

    $user = User::factory()->admin()->create();
    $file = File::factory()->create([
        'source' => 'Wallhaven',
        'url' => 'https://w.wallhaven.cc/full/example.jpg',
        'preview_url' => 'https://th.wallhaven.cc/small/example.jpg',
        'path' => null,
        'preview_path' => null,
        'downloaded' => false,
        'not_found' => false,
    ]);

    $tab = Tab::factory()->for($user)->withFiles([$file->id])->create();

    Http::fake();

    $response = $this->actingAs($user)->postJson("/api/files/{$file->id}/preview-failure");

    $response->assertNoContent();

    $file->refresh();
    $tab->refresh();

    expect($file->not_found)->toBeFalse()
        ->and($tab->files()->count())->toBe(1);

    Event::assertNotDispatched(FileMarkedNotFound::class);
    Http::assertNothingSent();
});
