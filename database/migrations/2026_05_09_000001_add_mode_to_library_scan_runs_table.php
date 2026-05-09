<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('library_scan_runs', 'mode')) {
            return;
        }

        Schema::table('library_scan_runs', function (Blueprint $table) {
            $table->string('mode')->default('scan')->after('id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('library_scan_runs', 'mode')) {
            return;
        }

        Schema::table('library_scan_runs', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
