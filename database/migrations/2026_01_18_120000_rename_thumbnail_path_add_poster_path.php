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
            if (! Schema::hasColumn('files', 'preview_url')) {
                $table->text('preview_url')->nullable()->after('url');
            }
            if (! Schema::hasColumn('files', 'preview_path')) {
                $table->text('preview_path')->nullable()->after('url');
            }
            if (! Schema::hasColumn('files', 'poster_path')) {
                $table->text('poster_path')->nullable()->after('preview_path');
            }
        });

        if (Schema::hasColumn('files', 'thumbnail_url')) {
            DB::table('files')
                ->whereNull('preview_url')
                ->update([
                    'preview_url' => DB::raw('thumbnail_url'),
                ]);

            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('thumbnail_url');
            });
        }

        if (Schema::hasColumn('files', 'thumbnail_path')) {
            DB::table('files')
                ->whereNull('preview_path')
                ->update([
                    'preview_path' => DB::raw('thumbnail_path'),
                ]);

            Schema::table('files', function (Blueprint $table) {
                $table->dropColumn('thumbnail_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            if (! Schema::hasColumn('files', 'thumbnail_url')) {
                $table->text('thumbnail_url')->nullable()->after('url');
            }
            if (! Schema::hasColumn('files', 'thumbnail_path')) {
                $table->text('thumbnail_path')->nullable()->after('url');
            }
        });

        if (Schema::hasColumn('files', 'preview_url')) {
            DB::table('files')
                ->whereNull('thumbnail_url')
                ->update([
                    'thumbnail_url' => DB::raw('preview_url'),
                ]);
        }

        if (Schema::hasColumn('files', 'preview_path')) {
            DB::table('files')
                ->whereNull('thumbnail_path')
                ->update([
                    'thumbnail_path' => DB::raw('preview_path'),
                ]);
        }

        Schema::table('files', function (Blueprint $table) {
            if (Schema::hasColumn('files', 'preview_url')) {
                $table->dropColumn('preview_url');
            }
            if (Schema::hasColumn('files', 'poster_path')) {
                $table->dropColumn('poster_path');
            }
            if (Schema::hasColumn('files', 'preview_path')) {
                $table->dropColumn('preview_path');
            }
        });
    }
};
