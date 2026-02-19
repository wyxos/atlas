<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addUrlHash = ! Schema::hasColumn('files', 'url_hash');
        $addReferrerUrlHash = ! Schema::hasColumn('files', 'referrer_url_hash');

        if ($addUrlHash || $addReferrerUrlHash) {
            Schema::table('files', function (Blueprint $table) use ($addUrlHash, $addReferrerUrlHash): void {
                if ($addUrlHash) {
                    $table->string('url_hash', 64)->nullable();
                }

                if ($addReferrerUrlHash) {
                    $table->string('referrer_url_hash', 64)->nullable();
                }
            });
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("
                UPDATE files
                SET
                    url_hash = CASE
                        WHEN url IS NULL OR TRIM(url) = '' THEN NULL
                        ELSE SHA2(TRIM(url), 256)
                    END,
                    referrer_url_hash = CASE
                        WHEN referrer_url IS NULL OR TRIM(referrer_url) = '' THEN NULL
                        ELSE SHA2(TRIM(referrer_url), 256)
                    END
            ");
        } else {
            DB::table('files')
                ->select(['id', 'url', 'referrer_url'])
                ->orderBy('id')
                ->chunkById(1000, function ($rows): void {
                    foreach ($rows as $row) {
                        $url = trim((string) ($row->url ?? ''));
                        $referrerUrl = trim((string) ($row->referrer_url ?? ''));

                        DB::table('files')
                            ->where('id', $row->id)
                            ->update([
                                'url_hash' => $url !== '' ? hash('sha256', $url) : null,
                                'referrer_url_hash' => $referrerUrl !== '' ? hash('sha256', $referrerUrl) : null,
                            ]);
                    }
                });
        }

        try {
            Schema::table('files', function (Blueprint $table): void {
                $table->index('url_hash', 'files_url_hash_index');
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }

        try {
            Schema::table('files', function (Blueprint $table): void {
                $table->index('referrer_url_hash', 'files_referrer_url_hash_index');
            });
        } catch (\Throwable) {
            // Ignore when index already exists.
        }
    }

    public function down(): void
    {
        try {
            Schema::table('files', function (Blueprint $table): void {
                $table->dropIndex('files_url_hash_index');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        try {
            Schema::table('files', function (Blueprint $table): void {
                $table->dropIndex('files_referrer_url_hash_index');
            });
        } catch (\Throwable) {
            // Ignore when index does not exist.
        }

        $dropUrlHash = Schema::hasColumn('files', 'url_hash');
        $dropReferrerUrlHash = Schema::hasColumn('files', 'referrer_url_hash');
        if ($dropUrlHash || $dropReferrerUrlHash) {
            Schema::table('files', function (Blueprint $table) use ($dropUrlHash, $dropReferrerUrlHash): void {
                if ($dropUrlHash) {
                    $table->dropColumn('url_hash');
                }

                if ($dropReferrerUrlHash) {
                    $table->dropColumn('referrer_url_hash');
                }
            });
        }
    }
};
