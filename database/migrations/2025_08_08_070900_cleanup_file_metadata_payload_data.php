<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Safety backfill in case some rows missed the first migration
        try {
            DB::statement(<<<'SQL'
                UPDATE files f
                JOIN file_metadata fm ON fm.file_id = f.id
                SET f.listing_metadata = JSON_EXTRACT(fm.payload, '$.data')
                WHERE f.listing_metadata IS NULL
                  AND JSON_EXTRACT(fm.payload, '$.data') IS NOT NULL
            SQL);
        } catch (\Throwable $e) {
            // Ignore if JSON functions not available or tables differ in some envs
        }

        // Remove the moved $.data key from file_metadata.payload to keep payload lean (e.g., width/height only)
        try {
            DB::statement(<<<'SQL'
                UPDATE file_metadata fm
                SET fm.payload = JSON_REMOVE(fm.payload, '$.data')
                WHERE JSON_EXTRACT(fm.payload, '$.data') IS NOT NULL
            SQL);
        } catch (\Throwable $e) {
            // Ignore if JSON functions not available
        }
    }

    public function down(): void
    {
        // No-op: we cannot reliably restore the original payload.data once removed
        // If needed, listing_metadata still contains the moved content
    }
};
