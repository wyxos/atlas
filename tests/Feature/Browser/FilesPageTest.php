<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    useTypesense();
    resetTypesenseFileCollection();
    \App\Models\File::search('warmup')->get();
});

test('files page renders and shows created files', function () {
    config()->set('scout.driver', 'typesense');
    config()->set('scout.queue', false);
    config()->set('scout.after_commit', false);

    $user = User::factory()->create();
    File::factory()->count(3)->create();
    // Ensure index reflects current data
    File::query()->searchable();

    $this->actingAs($user);

    File::query()->searchable();

    File::query()->searchable();

    $response = visit(route('files'));

    $response->assertNoSmoke();
    $response->assertSee('Files');
    $response->assertSee('Filename');
    $response->assertSee('MIME');
    $response->assertSee('Size');
    $response->assertSee('Created');
});

test('files pagination shows next page', function () {
    useTypesense();

    $user = User::factory()->create();

    File::factory()->count(25)->create();
    $sentinel = File::factory()->create(['filename' => 'pagination-sentinel.mp3', 'mime_type' => 'audio/mpeg']);
    File::factory()->count(25)->create();
    File::query()->searchable();

    $this->actingAs($user);

    $response = visit(route('files'));
    $response->assertNoSmoke();
    $response->assertSee('Files');
    $response->assertSee('Page 1 of');
    $response->assertDontSee('pagination-sentinel.mp3');

    $response->click('Next')
        ->assertNoSmoke()
        ->assertSee('Page 2 of')
        ->assertSee('pagination-sentinel.mp3');
});

test('clicking a filename opens a temporary URL and streams inline', function () {
    useTypesense();

    // Use the atlas disk
    Storage::fake('atlas');

    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a real file on the fake disk
    Storage::disk('atlas')->put('test/open-me.txt', 'hello world');

    // Create a file record pointing at it
    $file = File::factory()->create([
        'filename' => 'open-me.txt',
        'mime_type' => 'text/plain',
        'path' => 'test/open-me.txt',
    ]);

    $response = visit(route('files'));

    $response->assertNoSmoke();
    $response->assertSee('open-me.txt');

    // Clicking the link opens a new tab, so instead assert the signed URL streams inline.
    $signedUrl = URL::temporarySignedRoute('files.view', now()->addMinutes(5), ['file' => $file->id]);
    $stream = $this->get($signedUrl);
    $stream->assertOk();
    expect($stream->headers->get('content-disposition'))
        ->toBeString()
        ->toContain('inline')
        ->toContain('open-me.txt');
});

test('files filter: local only', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    File::factory()->create(['filename' => 'local-a.mp3', 'path' => 'media/a.mp3']);
    File::factory()->create(['filename' => 'local-b.mp3', 'path' => 'media/b.mp3']);
    File::factory()->create(['filename' => 'online-a.mp3', 'path' => null]);
    File::factory()->create(['filename' => 'online-b.mp3', 'path' => null]);

    File::query()->searchable();

    visit(route('files', ['origin' => 'local']))
        ->assertNoSmoke()
        ->assertSee('local-a.mp3')
        ->assertSee('local-b.mp3')
        ->assertDontSee('online-a.mp3')
        ->assertDontSee('online-b.mp3');
});

test('files filter: online only', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    File::factory()->create(['filename' => 'local-a.mp3', 'path' => 'media/a.mp3']);
    File::factory()->create(['filename' => 'local-b.mp3', 'path' => 'media/b.mp3']);
    File::factory()->create(['filename' => 'online-a.mp3', 'path' => null]);
    File::factory()->create(['filename' => 'online-b.mp3', 'path' => null]);

    File::query()->searchable();

    visit(route('files', ['origin' => 'online']))
        ->assertNoSmoke()
        ->assertSee('online-a.mp3')
        ->assertSee('online-b.mp3')
        ->assertDontSee('local-a.mp3')
        ->assertDontSee('local-b.mp3');
});

test('files filter: by query string', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    File::factory()->create(['filename' => 'local-a.mp3', 'path' => 'media/a.mp3']);
    File::factory()->create(['filename' => 'local-b.mp3', 'path' => 'media/b.mp3']);
    File::factory()->create(['filename' => 'online-a.mp3', 'path' => null]);
    File::factory()->create(['filename' => 'online-b.mp3', 'path' => null]);

    File::query()->searchable();

    visit(route('files', ['q' => 'local', 'origin' => 'both']))
        ->assertNoSmoke()
        ->assertSee('local-a.mp3')
        ->assertSee('local-b.mp3')
        ->assertDontSee('online-a.mp3')
        ->assertDontSee('online-b.mp3');
});

test('files sort: oldest shows the earliest created', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    $lA = File::factory()->create(['filename' => 'local-a.mp3', 'path' => 'media/a.mp3']);
    $lB = File::factory()->create(['filename' => 'local-b.mp3', 'path' => 'media/b.mp3']);
    $oA = File::factory()->create(['filename' => 'online-a.mp3', 'path' => null]);
    $oB = File::factory()->create(['filename' => 'online-b.mp3', 'path' => null]);

    // Force timestamps to control sort (created_at is guarded by fillable)
    $oA->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
    $lA->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
    $oB->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
    $lB->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    File::query()->searchable();

    visit(route('files', ['sort' => 'oldest']))
        ->assertNoSmoke()
        ->assertSee('online-a.mp3')
        ->click('Clear')
        ->assertUrlIs(route('files'));
});

test('blacklisted files are excluded from the list', function () {
    useTypesense();

    $user = User::factory()->create();
    $this->actingAs($user);

    // Create regular files
    File::factory()->create(['filename' => 'visible-1.mp3', 'blacklisted_at' => null]);
    File::factory()->create(['filename' => 'visible-2.mp3', 'blacklisted_at' => null]);

    // Create blacklisted files
    File::factory()->create(['filename' => 'blacklisted-1.mp3', 'blacklisted_at' => now(), 'blacklist_reason' => 'test']);
    File::factory()->create(['filename' => 'blacklisted-2.mp3', 'blacklisted_at' => now(), 'blacklist_reason' => 'test']);

    File::query()->searchable();

    visit(route('files'))
        ->assertNoSmoke()
        ->assertSee('visible-1.mp3')
        ->assertSee('visible-2.mp3')
        ->assertDontSee('blacklisted-1.mp3')
        ->assertDontSee('blacklisted-2.mp3');
});

test('local files can be deleted', function () {
    useTypesense();

    Storage::fake('atlas');

    $user = User::factory()->create();
    $this->actingAs($user);

    // Create a file on the disk
    Storage::disk('atlas')->put('test/deleteme.txt', 'delete this');

    // Create file records (explicitly not blacklisted)
    $localFile = File::factory()->create([
        'filename' => 'deleteme.txt',
        'path' => 'test/deleteme.txt',
        'blacklisted_at' => null,
    ]);
    $onlineFile = File::factory()->create([
        'filename' => 'online-file.mp3',
        'path' => null,
        'url' => 'https://example.com/file.mp3',
        'blacklisted_at' => null,
    ]);

    File::query()->searchable();

    // Visit the files page
    $response = $this->get(route('files'));
    $response->assertInertia(fn (Assert $page) => $page
        ->component('files/Index')
        ->has('files.data', 2)
    );

    // Verify that the local file has the has_path flag
    $filesData = $response->viewData('page')['props']['files']['data'];
    $localFileData = collect($filesData)->firstWhere('filename', 'deleteme.txt');
    expect($localFileData)->not->toBeNull();
    expect($localFileData['has_path'])->toBeTrue();

    // Delete the local file
    $deleteResponse = $this->delete(route('files.destroy', $localFile));
    $deleteResponse->assertOk();
    $deleteResponse->assertJson(['message' => 'File deleted successfully']);

    // Verify file is deleted from database
    $this->assertDatabaseMissing('files', ['id' => $localFile->id]);

    // Verify file is deleted from storage
    Storage::disk('atlas')->assertMissing('test/deleteme.txt');

    // Try to delete online file (should fail)
    $deleteOnlineResponse = $this->delete(route('files.destroy', $onlineFile));
    $deleteOnlineResponse->assertForbidden();
    $deleteOnlineResponse->assertJson(['message' => 'Cannot delete files without local storage paths']);

    // Online file should still exist
    $this->assertDatabaseHas('files', ['id' => $onlineFile->id]);
});
