<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up remnants from aborted previous attempts.
        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_original_url_hash_unique');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }
        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_url_hash_unique');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        if (Schema::hasColumn('files', 'original_url_hash')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('original_url_hash');
            });
        }
        if (Schema::hasColumn('files', 'original_url')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('original_url');
            });
        }
        if (Schema::hasColumn('files', 'url_hash')) {
            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('url_hash');
            });
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                UPDATE files
                SET url = NULLIF(TRIM(url), '')
            ");

            // Keep one row per canonical URL before adding unique(url).
            DB::statement('DROP TEMPORARY TABLE IF EXISTS atlas_file_url_keep');
            DB::statement('
                CREATE TEMPORARY TABLE atlas_file_url_keep (
                    url_hash VARCHAR(64) NOT NULL PRIMARY KEY,
                    keep_id BIGINT UNSIGNED NOT NULL
                )
            ');
            DB::statement('
                INSERT INTO atlas_file_url_keep (url_hash, keep_id)
                SELECT f1.url_hash, f1.id
                FROM (
                    SELECT SHA2(url, 256) AS url_hash, id, downloaded
                    FROM files
                    WHERE url IS NOT NULL
                ) f1
                LEFT JOIN (
                    SELECT SHA2(url, 256) AS url_hash, id, downloaded
                    FROM files
                    WHERE url IS NOT NULL
                ) f2
                  ON f1.url_hash = f2.url_hash
                 AND (
                   f2.downloaded > f1.downloaded
                   OR (f2.downloaded = f1.downloaded AND f2.id > f1.id)
                 )
                WHERE f2.id IS NULL
            ');
            DB::statement('
                DELETE f
                FROM files f
                INNER JOIN atlas_file_url_keep k ON k.url_hash = SHA2(f.url, 256)
                WHERE f.url IS NOT NULL
                  AND f.id <> k.keep_id
            ');
            DB::statement('DROP TEMPORARY TABLE atlas_file_url_keep');
        } else {
            DB::table('files')
                ->select(['id', 'url'])
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $url = is_string($row->url ?? null) ? trim((string) $row->url) : '';
                        DB::table('files')
                            ->where('id', $row->id)
                            ->update(['url' => $url !== '' ? $url : null]);
                    }
                });
        }

        // Referrer must be non-unique.
        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_referrer_url_unique');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->unique('url', 'files_url_unique');
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->index('referrer_url', 'files_referrer_url_index');
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropIndex('files_referrer_url_index');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_url_unique');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->unique('referrer_url', 'files_referrer_url_unique');
            });
        } catch (\Throwable) {
            // Re-adding may fail when data contains duplicate referrers.
        }
    }
};
