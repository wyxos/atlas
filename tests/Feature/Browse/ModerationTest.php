<?php

use App\Http\Controllers\Concerns\ModeratesFiles;
use App\Jobs\DeleteAutoDislikedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

// Create a test class that uses the ModeratesFiles trait
class TestModerationController
{
    use ModeratesFiles;

    public function callModerateFiles($files)
    {
        return $this->moderateFiles($files);
    }
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->controller = new TestModerationController;
});

test('files with matching prompts are auto-disliked', function () {
    Bus::fake();

    // Create an active moderation rule
    $rule = ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'name' => 'Block spam',
        'active' => true,
    ]);

    // Create files with prompts
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_disliked' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file1->load('metadata');

    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'Beautiful landscape photo'],
    ]);
    $file2->load('metadata');

    // Call moderateFiles directly
    $result = $this->controller->callModerateFiles(collect([$file1, $file2]));

    // Assert file1 is auto-disliked
    expect($file1->fresh()->auto_disliked)->toBeTrue()
        ->and($file2->fresh()->auto_disliked)->toBeFalse();

    // Assert moderation metadata is stored
    $metadata1 = FileMetadata::where('file_id', $file1->id)->first();
    expect($metadata1->payload['moderation'])->toHaveKeys(['reason', 'rule_id', 'rule_name', 'hits'])
        ->and($metadata1->payload['moderation']['reason'])->toBe('moderation:rule')
        ->and($metadata1->payload['moderation']['rule_id'])->toBe($rule->id)
        ->and($metadata1->payload['moderation']['rule_name'])->toBe('Block spam');

    // Assert deletion job is dispatched for file1
    Bus::assertDispatched(DeleteAutoDislikedFileJob::class, function ($job) use ($file1) {
        return $job->filePath === $file1->path;
    });

    // Assert file2 has no moderation metadata
    $metadata2 = FileMetadata::where('file_id', $file2->id)->first();
    expect($metadata2->payload['moderation'] ?? null)->toBeNull();
});

test('files without matching prompts are not auto-disliked', function () {
    Bus::fake();

    // Create an active moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => true,
    ]);

    // Create file with non-matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'Beautiful landscape photo'],
    ]);
    $file->load('metadata');

    // Call moderateFiles directly
    $this->controller->callModerateFiles(collect([$file]));

    // Assert file is not auto-disliked
    expect($file->fresh()->auto_disliked)->toBeFalse();

    // Assert no deletion job is dispatched
    Bus::assertNothingDispatched();
});

test('inactive rules are ignored', function () {
    Bus::fake();

    // Create an inactive moderation rule
    ModerationRule::factory()->any(['spam', 'advertisement'])->create([
        'active' => false,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is a spam advertisement'],
    ]);
    $file->load('metadata');

    // Call moderateFiles directly
    $this->controller->callModerateFiles(collect([$file]));

    // Assert file is not auto-disliked
    expect($file->fresh()->auto_disliked)->toBeFalse();

    // Assert no deletion job is dispatched
    Bus::assertNothingDispatched();
});

test('multiple active rules are checked', function () {
    Bus::fake();

    // Create multiple active rules
    $rule1 = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);
    $rule2 = ModerationRule::factory()->any(['advertisement'])->create([
        'name' => 'Ad rule',
        'active' => true,
    ]);

    // Create file matching first rule
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_disliked' => false,
        'path' => 'test/path1.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file1->load('metadata');

    // Create file matching second rule
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
        'path' => 'test/path2.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file2->id,
        'payload' => ['prompt' => 'This is an advertisement'],
    ]);
    $file2->load('metadata');

    // Call moderateFiles directly
    $this->controller->callModerateFiles(collect([$file1, $file2]));

    // Assert both files are auto-disliked
    expect($file1->fresh()->auto_disliked)->toBeTrue()
        ->and($file2->fresh()->auto_disliked)->toBeTrue();

    // Assert correct rule metadata is stored
    $metadata1 = FileMetadata::where('file_id', $file1->id)->first();
    expect($metadata1->payload['moderation']['rule_id'])->toBe($rule1->id);

    $metadata2 = FileMetadata::where('file_id', $file2->id)->first();
    expect($metadata2->payload['moderation']['rule_id'])->toBe($rule2->id);
});

test('files already auto-disliked are handled correctly', function () {
    Bus::fake();

    // Create an active moderation rule
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);

    // Create file already auto-disliked but without moderation metadata
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => true,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    // Call moderateFiles directly
    $this->controller->callModerateFiles(collect([$file]));

    // Assert file remains auto-disliked
    expect($file->fresh()->auto_disliked)->toBeTrue();

    // Assert moderation metadata is now populated
    $metadata = FileMetadata::where('file_id', $file->id)->first();
    expect($metadata->payload['moderation'])->toHaveKeys(['reason', 'rule_id', 'rule_name', 'hits'])
        ->and($metadata->payload['moderation']['rule_id'])->toBe($rule->id);

    // Assert no deletion job is dispatched (file already processed)
    Bus::assertNothingDispatched();
});

test('files without prompts are skipped', function () {
    Bus::fake();

    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
    ]);

    // Create file without prompt
    $file1 = File::factory()->create([
        'referrer_url' => 'https://example.com/file1.jpg',
        'auto_disliked' => false,
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file1->id,
        'payload' => [], // No prompt
    ]);
    $file1->load('metadata');

    // Create file without metadata
    $file2 = File::factory()->create([
        'referrer_url' => 'https://example.com/file2.jpg',
        'auto_disliked' => false,
    ]);

    // Call moderateFiles directly
    $this->controller->callModerateFiles(collect([$file1, $file2]));

    // Assert files are not auto-disliked
    expect($file1->fresh()->auto_disliked)->toBeFalse()
        ->and($file2->fresh()->auto_disliked)->toBeFalse();

    // Assert no deletion jobs are dispatched
    Bus::assertNothingDispatched();
});

test('moderation result includes correct data', function () {
    Bus::fake();

    // Create an active moderation rule
    $rule = ModerationRule::factory()->any(['spam'])->create([
        'name' => 'Spam rule',
        'active' => true,
    ]);

    // Create file with matching prompt
    $file = File::factory()->create([
        'referrer_url' => 'https://example.com/file.jpg',
        'auto_disliked' => false,
        'thumbnail_url' => 'https://example.com/thumb.jpg',
        'filename' => 'test.jpg',
        'path' => 'test/path.jpg',
    ]);
    FileMetadata::factory()->create([
        'file_id' => $file->id,
        'payload' => ['prompt' => 'This is spam content'],
    ]);
    $file->load('metadata');

    // Call moderateFiles directly
    $result = $this->controller->callModerateFiles(collect([$file]));

    // Assert moderation data is in result
    expect($result)->toHaveKeys(['filtered', 'removedIds', 'previewBag', 'newlyAutoDislikedCount'])
        ->and($result['newlyAutoDislikedCount'])->toBe(1)
        ->and($result['removedIds'])->toContain($file->id)
        ->and($result['previewBag'])->toBeArray()
        ->and(count($result['previewBag']))->toBe(1)
        ->and($result['previewBag'][0]['id'])->toBe($file->id);
});

test('batch update works correctly for multiple files', function () {
    Bus::fake();

    // Create an active moderation rule
    ModerationRule::factory()->any(['spam'])->create([
        'active' => true,
    ]);

    // Create multiple files with matching prompts
    $files = collect(range(1, 5))->map(function ($i) {
        $file = File::factory()->create([
            'referrer_url' => "https://example.com/file{$i}.jpg",
            'auto_disliked' => false,
            'path' => "test/path{$i}.jpg",
        ]);
        FileMetadata::factory()->create([
            'file_id' => $file->id,
            'payload' => ['prompt' => 'This is spam content'],
        ]);
        $file->load('metadata');

        return $file;
    });

    // Call moderateFiles directly
    $result = $this->controller->callModerateFiles($files);

    // Assert all files are auto-disliked
    foreach ($files as $file) {
        expect($file->fresh()->auto_disliked)->toBeTrue();
    }

    // Assert correct count in result
    expect($result['newlyAutoDislikedCount'])->toBe(5)
        ->and(count($result['removedIds']))->toBe(5);

    // Assert deletion jobs are dispatched for all files
    Bus::assertDispatched(DeleteAutoDislikedFileJob::class, 5);
});
