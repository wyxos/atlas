<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('library_scan_items', 'parser_queued_at')) {
            return;
        }

        Schema::table('library_scan_items', function (Blueprint $table) {
            $table->timestamp('parser_queued_at')->nullable()->after('parser')->index();
            $table->index(['library_scan_run_id', 'status', 'parser_queued_at'], 'scan_items_run_status_parser_queued_idx');
        });
    }

    public function down(): void
    {
        //
    }
};
