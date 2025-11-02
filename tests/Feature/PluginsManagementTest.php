<?php

use App\Jobs\ComposerInstallJob;
use App\Jobs\ComposerUninstallJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Bus::fake();
});

test('plugins index requires admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->get(route('plugins.edit'));

    $response->assertStatus(403);
});

test('plugins index returns merged data for admin', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->get(route('plugins.edit'));

    $response->assertStatus(200)
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Plugins')
            ->has('plugins')
        );
});

test('plugins index excludes atlas-plugin-contracts', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->get(route('plugins.edit'));

    $response->assertStatus(200);

    $plugins = $response->viewData('page')['props']['plugins'];
    $pluginNames = collect($plugins)->pluck('packageName')->toArray();

    expect($pluginNames)->not->toContain('wyxos/atlas-plugin-contracts');
});

test('install requires admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->post(route('plugins.install'), [
        'package' => 'wyxos/atlas-plugin-test',
    ]);

    $response->assertStatus(403);
});

test('install validates package name', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->post(route('plugins.install'), [
        'package' => 'invalid-package',
    ]);

    $response->assertStatus(302);
    $response->assertSessionHasErrors('package');
});

test('install rejects atlas-plugin-contracts', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->post(route('plugins.install'), [
        'package' => 'wyxos/atlas-plugin-contracts',
    ]);

    $response->assertStatus(302);
    $response->assertSessionHasErrors('package');
});

test('install updates composer.plugins.json and dispatches job', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $package = 'wyxos/atlas-plugin-test';

    $response = $this->from(route('plugins.edit'))->actingAs($user)->post(route('plugins.install'), [
        'package' => $package,
    ]);

    $response->assertRedirect(route('plugins.edit'));

    // Check composer.plugins.json was updated
    $pluginsJsonPath = base_path('composer.plugins.json');
    expect(is_file($pluginsJsonPath))->toBeTrue();

    $data = json_decode(file_get_contents($pluginsJsonPath), true);
    expect($data['require'])->toHaveKey($package);
    expect($data['require'][$package])->toBe('*@dev');

    // Check job was dispatched
    Bus::assertDispatched(ComposerInstallJob::class, function ($job) use ($user, $package) {
        return $job->userId === $user->id
            && $job->packageName === $package;
    });
});

test('uninstall requires admin', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->post(route('plugins.uninstall'), [
        'package' => 'wyxos/atlas-plugin-test',
    ]);

    $response->assertStatus(403);
});

test('uninstall validates package name', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $response = $this->actingAs($user)->post(route('plugins.uninstall'), [
        'package' => 'invalid-package',
    ]);

    $response->assertStatus(302);
    $response->assertSessionHasErrors('package');
});

test('uninstall removes from composer.plugins.json and dispatches job', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $package = 'wyxos/atlas-plugin-test';

    // Setup: add package to composer.plugins.json
    $pluginsJsonPath = base_path('composer.plugins.json');
    $data = ['require' => [$package => '*@dev']];
    file_put_contents($pluginsJsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $response = $this->from(route('plugins.edit'))->actingAs($user)->post(route('plugins.uninstall'), [
        'package' => $package,
    ]);

    $response->assertRedirect(route('plugins.edit'));

    // Check composer.plugins.json was updated
    $data = json_decode(file_get_contents($pluginsJsonPath), true);
    expect($data['require'])->not->toHaveKey($package);

    // Check job was dispatched
    Bus::assertDispatched(ComposerUninstallJob::class, function ($job) use ($user, $package) {
        return $job->userId === $user->id
            && $job->packageName === $package
            && $job->previousConstraint === '*@dev';
    });
});

test('concurrent operations are prevented', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $package = 'wyxos/atlas-plugin-test';

    // Set the lock
    cache()->put('composer_op:lock:'.$user->id, true, now()->addMinutes(5));

    $response = $this->from(route('plugins.edit'))->actingAs($user)->post(route('plugins.install'), [
        'package' => $package,
    ]);

    $response->assertRedirect(route('plugins.edit'));
    $response->assertSessionHasErrors([
        'package' => 'Another composer operation is in progress. Please wait.',
    ]);

    Bus::assertNotDispatched(ComposerInstallJob::class);
});
