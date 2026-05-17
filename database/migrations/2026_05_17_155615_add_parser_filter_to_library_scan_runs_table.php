<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('library_scan_runs', function (Blueprint $table) {
            $table->string('parser_filter')->nullable()->after('mode')->index('library_scan_runs_parser_filter_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::whenTableHasIndex('library_scan_runs', 'library_scan_runs_parser_filter_idx', static function (Blueprint $table): void {
            $table->dropIndex('library_scan_runs_parser_filter_idx');
        });

        if (Schema::hasColumn('library_scan_runs', 'parser_filter')) {
            Schema::table('library_scan_runs', static function (Blueprint $table): void {
                $table->dropColumn('parser_filter');
            });
        }
    }
};
