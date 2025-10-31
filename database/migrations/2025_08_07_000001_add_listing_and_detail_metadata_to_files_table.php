<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->json('listing_metadata')->nullable()->after('not_found');
            $table->json('detail_metadata')->nullable()->after('listing_metadata');
        });

        // Backfill from file_metadata.payload if present
        // Assumes a file_metadata table with columns: file_id, payload (JSON)
        // MariaDB/MySQL JSON operations using JSON_EXTRACT
        try {
            // listing_metadata: take payload.data when available
            DB::statement(<<<'SQL'
                UPDATE files f
                JOIN file_metadata fm ON fm.file_id = f.id
                SET f.listing_metadata = JSON_EXTRACT(fm.payload, '$.data')
                WHERE JSON_EXTRACT(fm.payload, '$.data') IS NOT NULL
            SQL);
        } catch (\Throwable $e) {
            // If JSON functions or table doesn't exist in certain envs, ignore backfill
        }
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['listing_metadata', 'detail_metadata']);
        });
    }
};
