<?php

use App\Http\Controllers\PhotosUnratedController;
use App\Models\File;
use App\Models\FileMetadata;
use App\Models\ModerationRule;
use App\Services\Plugin\PluginServiceResolver;
use Illuminate\Support\Collection;

it('demonstrates files blacklisted correctly but missing moderation data - simple rule', function () {
    // Create a simple "Cars" rule
    $rule = ModerationRule::factory()->create([
        'name' => 'Cars',
        'active' => true,
        'op' => 'any',
        'terms' => ['car', 'cars', 'chevrolet', 'lamborgini'],
        'options' => ['case_sensitive' => false, 'whole_word' => true],
    ]);

    // Create a file that matches the rule
    $file = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => null, // Not yet blacklisted
    ]);

    FileMetadata::factory()->for($file)->create([
        'payload' => ['prompt' => 'A beautiful Lamborgini on the highway'],
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

    // First run: File gets blacklisted and moderation data stored
    $result = $controller->exposeModerate(collect([$file->fresh('metadata')]));

    expect($result['removedIds'])->toContain($file->id);
    expect($result['newlyBlacklistedCount'])->toBeGreaterThanOrEqual(1);

    $file->refresh();
    expect($file->blacklisted_at)->not->toBeNull();
    expect($file->blacklist_reason)->toBe('moderation:rule');

    // Verify moderation data exists
    $metadata = $file->metadata()->first();
    expect(data_get($metadata?->payload, 'moderation.rule_id'))->toBe($rule->id);
    expect(data_get($metadata?->payload, 'moderation.hits'))->toContain('lamborgini');

    // Now simulate the bug: File is already blacklisted, so it gets skipped on next moderation run
    // This means if moderation runs again, the file won't get moderation data updated
    // But more importantly, if a file is manually blacklisted with reason 'moderation:rule'
    // but doesn't have moderation metadata, it will show null moderation rule and no highlights

    // Simulate: File was blacklisted but moderation data storage failed or was skipped
    $fileWithoutModerationData = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'moderation:rule', // Correctly blacklisted
    ]);

    FileMetadata::factory()->for($fileWithoutModerationData)->create([
        'payload' => ['prompt' => 'A red car with Lamborgini logo'], // No moderation data in payload
    ]);

    // When moderation runs, this file should still be processed to populate moderation data
    // even though it's already blacklisted
    $result2 = $controller->exposeModerate(collect([$fileWithoutModerationData->fresh('metadata')]));

    // File should NOT be re-blacklisted (already blacklisted)
    expect($result2['newlyBlacklistedCount'])->toBe(0);

    // File remains blacklisted AND now has moderation data
    $fileWithoutModerationData->refresh();
    expect($fileWithoutModerationData->blacklisted_at)->not->toBeNull();
    expect($fileWithoutModerationData->blacklist_reason)->toBe('moderation:rule');

    $metadata2 = $fileWithoutModerationData->metadata()->first();
    // FIXED: File now has moderation data populated
    expect(data_get($metadata2?->payload, 'moderation.rule_id'))->toBe($rule->id);
    expect(data_get($metadata2?->payload, 'moderation.hits'))->toContain('lamborgini');
});

it('demonstrates files blacklisted correctly but missing highlights - complex rule with NOT Any', function () {
    // Create a complex "Male" rule: AND (Any man, boy, male), (Any handsome, cute), (OR (Not Any Armor, sword) (solo, alone))
    $rule = ModerationRule::factory()->create([
        'name' => 'Male',
        'active' => true,
        'op' => 'and',
        'children' => [
            [
                'op' => 'any',
                'terms' => ['man', 'boy', 'male'],
                'options' => ['case_sensitive' => false, 'whole_word' => true],
            ],
            [
                'op' => 'any',
                'terms' => ['handsome', 'cute', 'blush', 'blushing'],
                'options' => ['case_sensitive' => false, 'whole_word' => true],
            ],
            [
                'op' => 'or',
                'children' => [
                    [
                        'op' => 'not_any',
                        'terms' => ['armor', 'sword', 'weapon'],
                        'options' => ['case_sensitive' => false, 'whole_word' => true],
                    ],
                    [
                        'op' => 'any',
                        'terms' => ['solo', 'alone', 'focus'],
                        'options' => ['case_sensitive' => false, 'whole_word' => true],
                    ],
                ],
            ],
        ],
    ]);

    // File 1: Matches via NOT Any clause (no armor/sword present)
    // This should match but collectMatches() won't return hits from the NOT Any clause
    $file1 = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => null,
    ]);

    FileMetadata::factory()->for($file1)->create([
        'payload' => ['prompt' => 'handsome man with cute smile'],
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

    $result = $controller->exposeModerate(collect([$file1->fresh('metadata')]));

    expect($result['removedIds'])->toContain($file1->id);
    expect($result['newlyBlacklistedCount'])->toBeGreaterThanOrEqual(1);

    $file1->refresh();
    expect($file1->blacklisted_at)->not->toBeNull();
    expect($file1->blacklist_reason)->toBe('moderation:rule');

    $metadata1 = $file1->metadata()->first();
    expect(data_get($metadata1?->payload, 'moderation.rule_id'))->toBe($rule->id);

    // BUG: The hits array only contains terms from the positive clauses (man, handsome, cute)
    // but NOT from the NOT Any clause, because gatherNodeMatches() for not_any only collects
    // terms that ARE present, not terms that are NOT present
    $hits1 = data_get($metadata1?->payload, 'moderation.hits', []);
    expect($hits1)->toBeArray();
    expect($hits1)->toContain('man');
    expect($hits1)->toContain('handsome');
    expect($hits1)->toContain('cute');
    // The NOT Any clause matches (no armor/sword present) but doesn't contribute to hits
    // This is expected behavior for NOT Any, but the user might expect to see why it matched

    // File 2: Matches via the OR clause (solo/alone present) instead of NOT Any
    $file2 = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => null,
    ]);

    FileMetadata::factory()->for($file2)->create([
        'payload' => ['prompt' => 'cute boy solo portrait'],
    ]);

    $result2 = $controller->exposeModerate(collect([$file2->fresh('metadata')]));

    expect($result2['removedIds'])->toContain($file2->id);

    $file2->refresh();
    $metadata2 = $file2->metadata()->first();
    $hits2 = data_get($metadata2?->payload, 'moderation.hits', []);

    // This file should have hits from both positive clauses AND the solo/alone term
    expect($hits2)->toContain('boy');
    expect($hits2)->toContain('cute');
    expect($hits2)->toContain('solo');
});

it('demonstrates already blacklisted files are skipped and never get moderation data', function () {
    $rule = ModerationRule::factory()->create([
        'name' => 'Cars',
        'active' => true,
        'op' => 'any',
        'terms' => ['car', 'lamborgini'],
        'options' => ['case_sensitive' => false, 'whole_word' => true],
    ]);

    // Create a file that's already blacklisted (maybe from a previous run or manually)
    $alreadyBlacklisted = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => now(),
        'blacklist_reason' => 'moderation:rule', // Set reason but no moderation metadata
    ]);

    FileMetadata::factory()->for($alreadyBlacklisted)->create([
        'payload' => ['prompt' => 'A Lamborgini sports car'],
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

    // When moderation runs, the file should be processed to populate moderation data
    // even though it's already blacklisted
    $result = $controller->exposeModerate(collect([$alreadyBlacklisted->fresh('metadata')]));

    // File should NOT be re-blacklisted (already blacklisted)
    expect($result['newlyBlacklistedCount'])->toBe(0);

    // File remains blacklisted AND now has moderation data
    $alreadyBlacklisted->refresh();
    expect($alreadyBlacklisted->blacklisted_at)->not->toBeNull();
    expect($alreadyBlacklisted->blacklist_reason)->toBe('moderation:rule');

    $metadata = $alreadyBlacklisted->metadata()->first();
    // FIXED: File now has moderation data populated
    expect(data_get($metadata?->payload, 'moderation.rule_id'))->toBe($rule->id);
    expect(data_get($metadata?->payload, 'moderation.hits'))->toContain('lamborgini');
});

it('demonstrates the exact bug: files blacklisted correctly but moderation rule is null and no highlights', function () {
    // This test demonstrates the EXACT bug the user reported:
    // - File is correctly blacklisted (blacklisted_at is set, blacklist_reason is 'moderation:rule')
    // - File appears in disliked > auto category
    // - But moderation rule value is null and no highlights

    $rule = ModerationRule::factory()->create([
        'name' => 'Cars',
        'active' => true,
        'op' => 'any',
        'terms' => ['car', 'cars', 'chevrolet', 'lamborgini'],
        'options' => ['case_sensitive' => false, 'whole_word' => true],
    ]);

    // Scenario 1: File was blacklisted in a previous moderation run
    // Then moderation runs again, but the file is skipped because it's already blacklisted
    // Result: File is blacklisted correctly but has no moderation data

    $file1 = File::factory()->create([
        'mime_type' => 'image/jpeg',
        'blacklisted_at' => now()->subHour(), // Already blacklisted
        'blacklist_reason' => 'moderation:rule',
    ]);

    FileMetadata::factory()->for($file1)->create([
        'payload' => ['prompt' => 'A Lamborgini sports car'], // No moderation data
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

    // Moderation runs - file should be processed to populate moderation data
    // even though it's already blacklisted
    $result1 = $controller->exposeModerate(collect([$file1->fresh('metadata')]));

    // File should NOT be re-blacklisted (already blacklisted)
    expect($result1['newlyBlacklistedCount'])->toBe(0);

    $file1->refresh();
    // File remains blacklisted AND now has moderation data
    expect($file1->blacklisted_at)->not->toBeNull();
    expect($file1->blacklist_reason)->toBe('moderation:rule');

    $metadata1 = $file1->metadata()->first();
    // FIXED: File now has moderation data populated
    expect(data_get($metadata1?->payload, 'moderation.rule_id'))->toBe($rule->id);
    expect(data_get($metadata1?->payload, 'moderation.hits'))->toContain('lamborgini');

    // Scenario 2: File gets blacklisted but moderation data isn't stored for some reason
    // (e.g., metadata doesn't exist yet and insert fails, or payload is corrupted)
    // This is less common but could happen

    // The key issue: Files that are already blacklisted are skipped entirely
    // So they never get moderation data stored, even if they should have it
});
