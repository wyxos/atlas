<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyTable = $this->legacyTable();

        if (Schema::hasTable($legacyTable) && ! Schema::hasTable('library_reindex_runs')) {
            Schema::rename($legacyTable, 'library_reindex_runs');
        }
    }

    public function down(): void
    {
        $legacyTable = $this->legacyTable();

        if (Schema::hasTable('library_reindex_runs') && ! Schema::hasTable($legacyTable)) {
            Schema::rename('library_reindex_runs', $legacyTable);
        }
    }

    private function legacyTable(): string
    {
        return 'local_'.'browse_reindex_runs';
    }
};
