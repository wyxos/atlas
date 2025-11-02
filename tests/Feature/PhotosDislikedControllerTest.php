<?php

use App\Http\Controllers\PhotosDislikedController;
use App\Models\User;

it('builds Scout query with previewed_count == 0 for auto category', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $controller = new class extends PhotosDislikedController
    {
        public function exposeBuild(string $category, string $sort = 'newest', $randSeed = null)
        {
            return $this->buildScoutQuery($category, $sort, $randSeed);
        }
    };

    $builder = $controller->exposeBuild('auto');

    // previewed_count should be constrained to exactly 0 via whereIn
    expect($builder->whereIns)->toHaveKey('previewed_count');
    expect($builder->whereIns['previewed_count'])->toBe([0]);

    // Also includes base filters
    expect($builder->wheres)->toHaveKey('mime_group');
    expect($builder->wheres['mime_group'])->toBe('image');
    expect($builder->wheres)->toHaveKey('blacklisted');
    expect($builder->wheres['blacklisted'])->toBe(true);
    expect($builder->wheres)->toHaveKey('not_found');
    expect($builder->wheres['not_found'])->toBe(false);
});

it('builds Scout query with previewed_count in [0..5] for non-auto disliked categories and excludes current user in not-disliked', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    // Sanity: actingAs took effect
    expect((string) (auth()->id() ?? ''))->toBe((string) $user->id);

    $controller = new class extends PhotosDislikedController
    {
        public function exposeBuild(string $category, string $sort = 'newest', $randSeed = null)
        {
            return $this->buildScoutQuery($category, $sort, $randSeed);
        }
    };

    $builder = $controller->exposeBuild('manual');
    // previewed_count should use an inclusive set in Typesense via whereIn
    expect($builder->whereIns)->toHaveKey('previewed_count');
    expect($builder->whereIns['previewed_count'])->toBe([0, 1, 2, 3, 4, 5]);

    // For not-disliked, ensure dislike_user_ids not-in contains current user id as string
    $builderNot = $controller->exposeBuild('not-disliked');
    expect($builderNot->whereNotIns)->toHaveKey('dislike_user_ids');
    expect($builderNot->whereNotIns['dislike_user_ids'])->toBe([strval($user->id)]);
});
