<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('files', 'original_url')) {
            Schema::table('files', function (Blueprint $table) {
                $table->text('original_url')->nullable()->after('url');
            });
        }

        if (! Schema::hasColumn('files', 'original_url_hash')) {
            Schema::table('files', function (Blueprint $table) {
                $table->string('original_url_hash', 64)->nullable()->after('original_url');
            });
        }

        DB::table('files')
            ->select(['id', 'original_url', 'original_url_hash', 'referrer_url', 'url'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $current = is_string($row->original_url ?? null) ? trim((string) $row->original_url) : '';
                    $currentHash = is_string($row->original_url_hash ?? null) ? trim((string) $row->original_url_hash) : '';

                    if ($current !== '' && $currentHash !== '') {
                        continue;
                    }

                    $fallback = '';
                    if ($current !== '') {
                        $fallback = $current;
                    } elseif (is_string($row->referrer_url ?? null) && trim((string) $row->referrer_url) !== '') {
                        $fallback = trim((string) $row->referrer_url);
                    } elseif (is_string($row->url ?? null) && trim((string) $row->url) !== '') {
                        $fallback = trim((string) $row->url);
                    }

                    DB::table('files')
                        ->where('id', $row->id)
                        ->update([
                            'original_url' => $fallback !== '' ? $fallback : null,
                            'original_url_hash' => $fallback !== '' ? hash('sha256', $fallback) : null,
                        ]);
                }
            });

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_referrer_url_unique');
            });
        } catch (\Throwable) {
            // Index may already be absent in some environments.
        }

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->unique('original_url_hash', 'files_original_url_hash_unique');
            });
        } catch (\Throwable) {
            // Index may already exist in some environments.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('files', function (Blueprint $table) {
                $table->dropUnique('files_original_url_hash_unique');
            });
        } catch (\Throwable) {
            // Index may already be absent.
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

        try {
            Schema::table('files', function (Blueprint $table) {
                $table->unique('referrer_url', 'files_referrer_url_unique');
            });
        } catch (\Throwable) {
            // Re-adding may fail when data now contains duplicate referrers.
        }
    }
};
