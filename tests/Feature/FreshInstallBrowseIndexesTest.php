<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function browseIndexNamesFor(string $table): array
{
    return collect(DB::select("PRAGMA index_list('{$table}')"))
        ->map(fn (object $index): string => $index->name)
        ->all();
}

test('fresh installs create browse indexes on files', function () {
    expect(browseIndexNamesFor('files'))
        ->toContain('files_downloaded_at_updated_at_id_idx')
        ->toContain('files_created_at_id_idx')
        ->toContain('files_updated_at_id_idx')
        ->toContain('files_blacklisted_at_updated_at_id_idx')
        ->toContain('files_source_updated_at_id_idx');
});

test('fresh installs create browse indexes on reactions', function () {
    expect(browseIndexNamesFor('reactions'))
        ->toContain('reactions_file_user_idx')
        ->toContain('reactions_file_user_type_idx');
});
