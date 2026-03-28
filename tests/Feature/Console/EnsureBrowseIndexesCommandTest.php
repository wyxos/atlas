<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('atlas:ensure-browse-indexes is a no-op on unsupported drivers', function () {
    $this->artisan('atlas:ensure-browse-indexes --dry-run')
        ->expectsOutputToContain('Skipping browse index DDL for sqlite')
        ->assertExitCode(0);
});

test('atlas:ensure-browse-indexes reports missing file indexes in dry-run mode', function () {
    $connection = \Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');

    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('select')->times(5)->andReturn([]);
    DB::shouldReceive('statement')->never();

    $this->artisan('atlas:ensure-browse-indexes --dry-run --only=files')
        ->expectsOutputToContain('Would create files_downloaded_at_updated_at_id_idx on files (downloaded_at, updated_at, id)')
        ->expectsOutputToContain('Would create files_source_updated_at_id_idx on files (source, updated_at, id)')
        ->assertExitCode(0);
});

test('atlas:ensure-browse-indexes creates missing reaction indexes with online ddl first', function () {
    $connection = \Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');

    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('select')->twice()->andReturn([], []);
    DB::shouldReceive('statement')->once()->with(
        'ALTER TABLE `reactions` ADD INDEX `reactions_file_user_idx` (`file_id`, `user_id`), ALGORITHM=INPLACE, LOCK=NONE'
    )->andReturn(true);
    DB::shouldReceive('statement')->once()->with(
        'ALTER TABLE `reactions` ADD INDEX `reactions_file_user_type_idx` (`file_id`, `user_id`, `type`), ALGORITHM=INPLACE, LOCK=NONE'
    )->andReturn(true);

    $this->artisan('atlas:ensure-browse-indexes --only=reactions')
        ->expectsOutputToContain('Created reactions_file_user_idx')
        ->expectsOutputToContain('Created reactions_file_user_type_idx')
        ->expectsOutputToContain('Browse index check complete. Created 2 index(es).')
        ->assertExitCode(0);
});

test('atlas:ensure-browse-indexes falls back to copy when inplace ddl is unsupported', function () {
    $connection = \Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');

    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('select')->twice()->andReturn([], [(object) ['Key_name' => 'reactions_file_user_type_idx']]);
    DB::shouldReceive('statement')->once()->with(
        'ALTER TABLE `reactions` ADD INDEX `reactions_file_user_idx` (`file_id`, `user_id`), ALGORITHM=INPLACE, LOCK=NONE'
    )->andThrow(new RuntimeException('ALGORITHM=INPLACE is not supported. Try ALGORITHM=COPY'));
    DB::shouldReceive('statement')->once()->with(
        'ALTER TABLE `reactions` ADD INDEX `reactions_file_user_idx` (`file_id`, `user_id`), ALGORITHM=COPY'
    )->andReturn(true);

    $this->artisan('atlas:ensure-browse-indexes --only=reactions')
        ->expectsOutputToContain('Created reactions_file_user_idx')
        ->expectsOutputToContain('Exists reactions_file_user_type_idx')
        ->assertExitCode(0);
});

test('atlas:ensure-browse-indexes stays idempotent when an index already exists or is created concurrently', function () {
    $connection = \Mockery::mock();
    $connection->shouldReceive('getDriverName')->once()->andReturn('mysql');

    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('select')->twice()->andReturn(
        [(object) ['Key_name' => 'reactions_file_user_idx']],
        [],
    );
    DB::shouldReceive('statement')->once()->with(
        'ALTER TABLE `reactions` ADD INDEX `reactions_file_user_type_idx` (`file_id`, `user_id`, `type`), ALGORITHM=INPLACE, LOCK=NONE'
    )->andThrow(new RuntimeException('Duplicate key name'));

    $this->artisan('atlas:ensure-browse-indexes --only=reactions')
        ->expectsOutputToContain('Exists reactions_file_user_idx')
        ->expectsOutputToContain('Exists reactions_file_user_type_idx')
        ->expectsOutputToContain('Browse index check complete. Created 0 index(es).')
        ->assertExitCode(0);
});
