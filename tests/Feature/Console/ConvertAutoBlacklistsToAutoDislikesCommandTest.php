<?php

use App\Jobs\ConvertAutoBlacklistsToAutoDislikes;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function addLegacyBlacklistReasonColumnForCommandTest(): void
{
    if (Schema::hasColumn('files', 'blacklist_reason')) {
        return;
    }

    Schema::table('files', function (Blueprint $table): void {
        $table->text('blacklist_reason')->nullable();
    });
}

test('conversion command dispatches the initial queued job', function () {
    addLegacyBlacklistReasonColumnForCommandTest();
    Bus::fake();

    $user = User::factory()->create();

    $this->artisan("atlas:convert-auto-blacklists-to-auto-dislikes --user-id={$user->id} --chunk=250 --queue=maintenance --start-id=42")
        ->assertExitCode(0);

    Bus::assertDispatched(ConvertAutoBlacklistsToAutoDislikes::class, function (ConvertAutoBlacklistsToAutoDislikes $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->afterId === 42
            && $job->chunk === 250
            && $job->queueName === 'maintenance'
            && $job->dryRun === false;
    });
});

test('conversion command dispatches dry run jobs', function () {
    addLegacyBlacklistReasonColumnForCommandTest();
    Bus::fake();

    $user = User::factory()->create();

    $this->artisan("atlas:convert-auto-blacklists-to-auto-dislikes --user-id={$user->id} --dry-run")
        ->assertExitCode(0);

    Bus::assertDispatched(ConvertAutoBlacklistsToAutoDislikes::class, fn (ConvertAutoBlacklistsToAutoDislikes $job): bool => $job->dryRun === true);
});

test('conversion command requires the legacy column to exist', function () {
    Bus::fake();

    $user = User::factory()->create();

    $this->artisan("atlas:convert-auto-blacklists-to-auto-dislikes --user-id={$user->id}")
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});

test('conversion command requires a valid user id', function () {
    addLegacyBlacklistReasonColumnForCommandTest();
    Bus::fake();

    $this->artisan('atlas:convert-auto-blacklists-to-auto-dislikes --user-id=999999')
        ->assertExitCode(1);

    Bus::assertNothingDispatched();
});
