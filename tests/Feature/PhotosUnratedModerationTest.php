<?php

use App\Http\Controllers\PhotosUnratedController;
use App\Jobs\DeleteBlacklistedFileJob;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Services\Plugin\PluginServiceResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

it('blacklists matched photos and records moderation metadata', function () {
    Queue::fake();

    $rule = ModerationRule::factory()->create([
        'terms' => ['banned'],
        'options' => ['case_sensitive' => false, 'whole_word' => false],
    ]);

    /** @var File $file */
    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'path' => 'photos/example.jpg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['prompt' => 'This prompt includes the banned term.'],
    ]);

    $controller = new class(app(PluginServiceResolver::class)) extends PhotosUnratedController
    {
        public function __construct(PluginServiceResolver $resolver)
        {
            parent::__construct($resolver);
        }

        public function exposeModerate(Collection $files): array
        {
            return $this->moderateFiles($files);
        }
    };

    $result = $controller->exposeModerate(collect([$file->fresh('metadata')]));

    expect($result['filtered'])->toHaveCount(0);
    expect($result['removedIds'])->toEqual([$file->id]);
    expect($result['newlyBlacklistedCount'])->toBeGreaterThanOrEqual(1);

    $file->refresh();
    expect($file->blacklisted_at)->not->toBeNull();

    $metadata = $file->metadata()->first();
    expect(data_get($metadata?->payload, 'moderation.rule_id'))->toBe($rule->id);
    expect(data_get($metadata?->payload, 'moderation.hits'))->toContain('banned');

    Queue::assertPushed(DeleteBlacklistedFileJob::class, fn ($job) => $job->filePath === $file->path);
});

it('returns files untouched when no moderation rule matches', function () {
    Queue::fake();

    ModerationRule::factory()->create([
        'terms' => ['forbidden'],
        'options' => ['case_sensitive' => false, 'whole_word' => true],
    ]);

    /** @var File $file */
    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'thumbnail_url' => 'https://example.com/thumb.jpg',
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['prompt' => 'Safe prompt content without matches.'],
    ]);

    $controller = new class(app(PluginServiceResolver::class)) extends PhotosUnratedController
    {
        public function __construct(PluginServiceResolver $resolver)
        {
            parent::__construct($resolver);
        }

        public function exposeModerate(Collection $files): array
        {
            return $this->moderateFiles($files);
        }
    };

    $result = $controller->exposeModerate(collect([$file->fresh('metadata')]));

    expect($result['filtered'])->toHaveCount(1);
    expect($result['removedIds'])->toBeEmpty();
    expect($result['newlyBlacklistedCount'])->toBe(0);

    $file->refresh();
    expect($file->blacklisted_at)->toBeNull();

    Queue::assertNotPushed(DeleteBlacklistedFileJob::class);
});
