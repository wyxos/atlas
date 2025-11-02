<?php

use App\Browser;
use App\Jobs\DeleteBlacklistedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    // Define mockBrowseService helper in test context
    $this->mockBrowseService = function (array $files) {
        $this->mock(\App\Services\BrowsePersister::class, function ($mock) use ($files) {
            // Mock persist to return files filtered by blacklist status (matching real behavior)
            $mock->shouldReceive('persist')
                ->once()
                ->andReturnUsing(function () use ($files) {
                    // Filter out already blacklisted files (matching real BrowsePersister behavior)
                    return array_filter($files, fn ($f) => ! $f->blacklisted_at);
                });
        });

        $this->mock(\App\Services\CivitAiImages::class, function ($mock) use ($files) {
            $mock->shouldReceive('fetch')
                ->andReturn(['items' => []]);

            $mock->shouldReceive('transform')
                ->andReturn([
                    'files' => array_map(fn ($f) => [
                        'id' => $f->id,
                        'url' => $f->url,
                        'thumbnail_url' => $f->thumbnail_url,
                        'source' => $f->source,
                    ], $files),
                    'filter' => [],
                ]);

            $mock->shouldReceive('defaultParams')->andReturn([]);
            $mock->shouldReceive('containers')->andReturn([]);
            $mock->shouldReceive('setParams')->andReturnSelf();

            $mock->shouldReceive('hotlinkProtected')
                ->andReturn(false);

            $mock->shouldReceive('source')
                ->andReturn('test');

            $mock->shouldReceive('label')
                ->andReturn('Test Service');

            $mock->allows('key')->andReturn('test-service');
        });
    };
});

it('evaluates moderation rules and blacklists matching files', function () {
    // Create a moderation rule
    $rule = ModerationRule::factory()->create([
        'name' => 'Block Car Terms',
        'active' => true,
        'op' => 'any',
        'terms' => ['car', 'automobile'],
        'options' => ['whole_word' => true, 'case_sensitive' => false],
    ]);

    // Create files with prompts
    $matchingFile = File::factory()->create([
        'source' => 'test',
        'url' => 'https://example.com/car.jpg',
        'thumbnail_url' => 'https://example.com/car-thumb.jpg',
        'listing_metadata' => [
            'meta' => ['prompt' => 'A red car on the street'],
        ],
    ]);

    $nonMatchingFile = File::factory()->create([
        'source' => 'test',
        'url' => 'https://example.com/bike.jpg',
        'thumbnail_url' => 'https://example.com/bike-thumb.jpg',
        'listing_metadata' => [
            'meta' => ['prompt' => 'A bicycle in the park'],
        ],
    ]);

    // Create metadata for files
    FileMetadata::factory()->for($matchingFile)->create(['payload' => []]);
    FileMetadata::factory()->for($nonMatchingFile)->create(['payload' => []]);

    // Mock the service to return our files
    ($this->mockBrowseService)([$matchingFile, $nonMatchingFile]);

    // Trigger Browser
    $result = Browser::handle();

    // Matching file should be blacklisted and excluded from results
    expect($result['files'])->toHaveCount(1);
    expect($result['files'][0]['id'])->toBe($nonMatchingFile->id);

    // Check moderation stats
    expect($result['moderation']['blacklisted_count'])->toBe(1);
    expect($result['moderation']['ids'])->toContain($matchingFile->id);

    // Verify file was blacklisted in database
    $matchingFile->refresh();
    expect($matchingFile->blacklisted_at)->not->toBeNull();
    expect($matchingFile->blacklist_reason)->toBe('moderation:rule');
});

it('bulk updates moderation metadata for all matched files', function () {
    $rule = ModerationRule::factory()->create([
        'name' => 'Santa Rule',
        'active' => true,
        'op' => 'all',
        'terms' => ['santa', 'red'],
        'options' => ['whole_word' => true, 'case_sensitive' => false],
    ]);

    // Create 3 matching files
    $file1 = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'Santa in red suit']],
    ]);
    $file2 = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'Red santa hat']],
    ]);
    $file3 = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'Jolly santa with red coat']],
    ]);

    // File 1 has existing metadata, files 2 & 3 don't
    FileMetadata::factory()->for($file1)->create([
        'payload' => ['existing' => 'data'],
    ]);

    ($this->mockBrowseService)([$file1, $file2, $file3]);

    Browser::handle();

    // All files should have moderation metadata
    // Need to load() instead of refresh() to properly reload relationships
    $file1->load('metadata');
    $file2->load('metadata');
    $file3->load('metadata');

    $meta1 = $file1->metadata->payload;
    $meta2 = $file2->metadata->payload;
    $meta3 = $file3->metadata->payload;

    // Check all have moderation data
    expect($meta1['moderation']['reason'])->toBe('moderation:rule');
    expect($meta1['moderation']['rule_id'])->toBe($rule->id);
    expect($meta1['moderation']['rule_name'])->toBe('Santa Rule');
    expect($meta1['moderation']['options'])->toEqual(['whole_word' => true, 'case_sensitive' => false]);
    expect($meta1['moderation']['hits'])->toContain('santa');
    expect($meta1['moderation']['hits'])->toContain('red');

    // File 1 should preserve existing data
    expect($meta1['existing'])->toBe('data');

    // Files 2 & 3 should have moderation data
    expect($meta2['moderation']['reason'])->toBe('moderation:rule');
    expect($meta2['moderation']['rule_id'])->toBe($rule->id);

    expect($meta3['moderation']['reason'])->toBe('moderation:rule');
    expect($meta3['moderation']['rule_id'])->toBe($rule->id);
});

it('respects rule options when matching', function () {
    // Case-sensitive rule
    $rule = ModerationRule::factory()->create([
        'name' => 'Case Sensitive Rule',
        'active' => true,
        'op' => 'any',
        'terms' => ['car'],
        'options' => ['whole_word' => true, 'case_sensitive' => true],
    ]);

    $lowerCaseFile = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'A car on the street']],
    ]);

    $upperCaseFile = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'A Car on the street']],
    ]);

    FileMetadata::factory()->for($lowerCaseFile)->create(['payload' => []]);
    FileMetadata::factory()->for($upperCaseFile)->create(['payload' => []]);

    ($this->mockBrowseService)([$lowerCaseFile, $upperCaseFile]);

    $result = Browser::handle();

    // Only lowercase 'car' should match (case_sensitive: true)
    expect($result['moderation']['blacklisted_count'])->toBe(1);
    expect($result['moderation']['ids'])->toContain($lowerCaseFile->id);
    expect($result['moderation']['ids'])->not->toContain($upperCaseFile->id);

    // Only lowercase file should have moderation metadata
    $lowerCaseFile->refresh();
    $upperCaseFile->refresh();

    expect($lowerCaseFile->metadata->payload['moderation'] ?? null)->not->toBeNull();
    expect($upperCaseFile->metadata->payload['moderation'] ?? null)->toBeNull();
});

it('dispatches file deletion jobs for blacklisted files with paths', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'any',
        'terms' => ['blocked'],
    ]);

    $fileWithPath = File::factory()->create([
        'source' => 'test',
        'path' => 'files/blocked-image.jpg',
        'listing_metadata' => ['meta' => ['prompt' => 'blocked content']],
    ]);

    $fileWithoutPath = File::factory()->create([
        'source' => 'test',
        'path' => null,
        'listing_metadata' => ['meta' => ['prompt' => 'blocked stuff']],
    ]);

    FileMetadata::factory()->for($fileWithPath)->create(['payload' => []]);
    FileMetadata::factory()->for($fileWithoutPath)->create(['payload' => []]);

    ($this->mockBrowseService)([$fileWithPath, $fileWithoutPath]);

    Browser::handle();

    // Only file with path should have job dispatched
    Queue::assertPushed(DeleteBlacklistedFileJob::class, 1);
    Queue::assertPushed(DeleteBlacklistedFileJob::class, function ($job) use ($fileWithPath) {
        return $job->filePath === $fileWithPath->path;
    });
});

it('handles files with no metadata gracefully', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'any',
        'terms' => ['test'],
    ]);

    // File without any metadata record
    $file = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'test content']],
    ]);

    ($this->mockBrowseService)([$file]);

    Browser::handle();

    // Should create metadata with moderation info
    $file->load('metadata');
    expect($file->metadata)->not->toBeNull();
    expect($file->metadata->payload['moderation']['reason'])->toBe('moderation:rule');
});

it('skips already blacklisted files', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'any',
        'terms' => ['blocked'],
    ]);

    $alreadyBlacklisted = File::factory()->create([
        'source' => 'test',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'manual',
        'listing_metadata' => ['meta' => ['prompt' => 'blocked content']],
    ]);

    FileMetadata::factory()->for($alreadyBlacklisted)->create(['payload' => []]);

    ($this->mockBrowseService)([$alreadyBlacklisted]);

    $result = Browser::handle();

    // Should not increment blacklist count (already blacklisted)
    expect($result['moderation']['blacklisted_count'])->toBe(0);

    // Should still be filtered from results
    expect($result['files'])->toHaveCount(0);
});

it('handles files without prompts', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'any',
        'terms' => ['test'],
    ]);

    $fileNoPrompt = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => []],
    ]);

    FileMetadata::factory()->for($fileNoPrompt)->create(['payload' => []]);

    ($this->mockBrowseService)([$fileNoPrompt]);

    $result = Browser::handle();

    // Should not be blacklisted (no prompt to match)
    expect($result['files'])->toHaveCount(1);
    expect($result['moderation']['blacklisted_count'])->toBe(0);

    $fileNoPrompt->refresh();
    expect($fileNoPrompt->blacklisted_at)->toBeNull();
});

it('matches first applicable rule only', function () {
    // Create two rules that both match
    $rule1 = ModerationRule::factory()->create([
        'name' => 'Rule 1',
        'active' => true,
        'op' => 'any',
        'terms' => ['car'],
    ]);

    $rule2 = ModerationRule::factory()->create([
        'name' => 'Rule 2',
        'active' => true,
        'op' => 'any',
        'terms' => ['car', 'automobile'],
    ]);

    $file = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'A car on the road']],
    ]);

    FileMetadata::factory()->for($file)->create(['payload' => []]);

    ($this->mockBrowseService)([$file]);

    Browser::handle();

    // Should use Rule 1 (first matching rule)
    $file->refresh();
    expect($file->metadata->payload['moderation']['rule_id'])->toBe($rule1->id);
    expect($file->metadata->payload['moderation']['rule_name'])->toBe('Rule 1');
});

it('handles phrase matching with underscores and spaces', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'any',
        'terms' => ['red car'],
        'options' => ['whole_word' => true, 'case_sensitive' => false],
    ]);

    $spacedFile = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'A red car zooms by']],
    ]);

    $underscoredFile = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'A red_car nearby']],
    ]);

    FileMetadata::factory()->for($spacedFile)->create(['payload' => []]);
    FileMetadata::factory()->for($underscoredFile)->create(['payload' => []]);

    ($this->mockBrowseService)([$spacedFile, $underscoredFile]);

    $result = Browser::handle();

    // Both should match (space/underscore interchangeability)
    expect($result['moderation']['blacklisted_count'])->toBe(2);
    expect($result['moderation']['ids'])->toContain($spacedFile->id);
    expect($result['moderation']['ids'])->toContain($underscoredFile->id);
});

it('collects matched terms in hits array', function () {
    $rule = ModerationRule::factory()->create([
        'active' => true,
        'op' => 'all',
        'terms' => ['santa', 'red', 'beard'],
        'options' => ['whole_word' => true, 'case_sensitive' => false],
    ]);

    $file = File::factory()->create([
        'source' => 'test',
        'listing_metadata' => ['meta' => ['prompt' => 'Santa with red coat and white beard']],
    ]);

    FileMetadata::factory()->for($file)->create(['payload' => []]);

    ($this->mockBrowseService)([$file]);

    Browser::handle();

    $file->refresh();
    $hits = $file->metadata->payload['moderation']['hits'];

    expect($hits)->toBeArray();
    expect($hits)->toContain('santa');
    expect($hits)->toContain('red');
    expect($hits)->toContain('beard');
});
